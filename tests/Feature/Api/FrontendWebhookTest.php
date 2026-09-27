<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Jobs\NotifyFrontendOfChange;
use App\Models\Category;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Services\Seo\UrlBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

/**
 * The publish webhook (item 4): telling a Next.js frontend that a page it caches has
 * stopped being correct.
 *
 * Without it the options are both bad — revalidate on a fixed timer, so a correction
 * waits out an interval unrelated to when anything changed, or render every request and
 * throw away the caching the framework exists to provide.
 */
beforeEach(function (): void {
    config()->set('cms.webhooks.endpoints', 'https://www.example.test/api/revalidate');
    config()->set('cms.webhooks.secret', 'webhook-secret');
});

it('queues nothing at all when no webhook is configured', function (): void {
    /*
     * The default, and it has to cost nothing: a site with no frontend hook must not
     * queue a job per editor save that will only discard itself.
     */
    config()->set('cms.webhooks.endpoints', null);
    config()->set('cms.webhooks.secret', null);

    Queue::fake();

    Content::factory()->published()->create();

    // Not assertNothingPushed(): a save legitimately queues SyncSearchIndexes, so the
    // assertion has to name the job under test.
    Queue::assertNotPushed(NotifyFrontendOfChange::class);
});

it('sends nothing when an endpoint is configured without a secret', function (): void {
    /*
     * Deliberately not a fallback to unsigned requests. The receiver is a public endpoint
     * that triggers work, so a silent downgrade to no authentication is worse than a
     * feature that is plainly off.
     */
    config()->set('cms.webhooks.secret', null);

    Queue::fake();

    Content::factory()->published()->create();

    Queue::assertNotPushed(NotifyFrontendOfChange::class);
    expect(NotifyFrontendOfChange::isConfigured())->toBeFalse();
});

it('queues a notification when a record with a public URL is saved', function (): void {
    Queue::fake();

    Content::factory()->published()->create();

    Queue::assertPushed(NotifyFrontendOfChange::class, 1);
});

it('notifies for every routable type but not for media', function (): void {
    Queue::fake();

    Content::factory()->published()->create();
    Page::factory()->create();
    Category::factory()->create();

    Queue::assertPushed(NotifyFrontendOfChange::class, 3);

    /*
     * A MediaAsset change alters what the API returns, but there is no page for it — a
     * frontend cannot revalidate "the alt text" — so it is left to the cache observer and
     * the frontend's own max-age.
     */
    Queue::fake();

    MediaAsset::factory()->create();

    Queue::assertNotPushed(NotifyFrontendOfChange::class);
});

it('posts the changed URLs per locale, signed', function (): void {
    Http::fake(['*' => Http::response('', 204)]);

    // The default queue driver is sync, so the observer's own dispatch would run the
    // job for real and double every assertion below. Only the explicit handle() under
    // test should send anything.
    Queue::fake();

    $content = Content::factory()->published()->create([
        'slug' => ['fa' => 'خبر-مهم', 'en' => 'big-news'],
    ]);

    (new NotifyFrontendOfChange($content::class, $content->getKey(), 'saved'))
        ->handle(app(UrlBuilder::class));

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        expect($body['event'])->toBe('saved')
            // `news`, matching the Delivery API's own vocabulary — a webhook naming
            // something a frontend developer cannot find in the route list is a puzzle
            // for no reason.
            ->and($body['resource'])->toBe('news')
            ->and($body['urls']['fa'])->toContain('/fa/news/خبر-مهم')
            ->and($body['urls']['en'])->toContain('/en/news/big-news');

        /*
         * The timestamp is INSIDE the signed string, not merely sent beside it. Signing
         * the body alone would let a captured request be replayed forever with a fresh
         * timestamp header, which is the thing the header exists to prevent.
         */
        $expected = hash_hmac(
            'sha256',
            $request->header('X-CMS-Timestamp')[0].'.'.$request->body(),
            'webhook-secret',
        );

        expect($request->header('X-CMS-Signature')[0])->toBe('sha256='.$expected);

        return true;
    });
});

it('sends the event even for a record that has since been deleted', function (): void {
    Http::fake(['*' => Http::response('', 204)]);

    // A page that no longer exists is exactly what a frontend needs to stop serving, so
    // the event still goes — it just has no URLs left to name.
    (new NotifyFrontendOfChange(Content::class, 999_999, 'deleted'))
        ->handle(app(UrlBuilder::class));

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['event'] === 'deleted' && $body['urls'] === [];
    });
});

it('posts to every configured endpoint', function (): void {
    // Comma-separated, so a deployment can notify a preview build as well as production.
    config()->set('cms.webhooks.endpoints', 'https://a.example.test/hook, https://b.example.test/hook');

    Http::fake(['*' => Http::response('', 204)]);

    // The default queue driver is sync, so the observer's own dispatch would run the
    // job for real and double every assertion below. Only the explicit handle() under
    // test should send anything.
    Queue::fake();

    $content = Content::factory()->published()->create();

    (new NotifyFrontendOfChange($content::class, $content->getKey(), 'saved'))
        ->handle(app(UrlBuilder::class));

    Http::assertSentCount(2);
});

it('gives up without retrying when the receiver rejects the signature', function (): void {
    Http::fake(['*' => Http::response('bad signature', 401)]);

    // The default queue driver is sync, so the observer's own dispatch would run the
    // job for real and double every assertion below. Only the explicit handle() under
    // test should send anything.
    Queue::fake();

    $content = Content::factory()->published()->create();

    /*
     * A 4xx is a configuration error — a wrong URL, a rejected secret — and will be just
     * as wrong on the fourth attempt. Retrying only delays the operator noticing and
     * fills failed_jobs with a fault no retry can fix, so this returns rather than
     * throwing.
     */
    (new NotifyFrontendOfChange($content::class, $content->getKey(), 'saved'))
        ->handle(app(UrlBuilder::class));

    Http::assertSentCount(1);
});

it('retries when the frontend is unavailable or rate limiting', function (int $status): void {
    Http::fake(['*' => Http::response('', $status)]);

    // The default queue driver is sync, so the observer's own dispatch would run the
    // job for real and double every assertion below. Only the explicit handle() under
    // test should send anything.
    Queue::fake();

    $content = Content::factory()->published()->create();

    // 429 and 5xx both mean "ask again", which for a queued job means throwing so the
    // spaced backoff applies — the usual cause is a deployment in progress.
    expect(fn () => (new NotifyFrontendOfChange($content::class, $content->getKey(), 'saved'))
        ->handle(app(UrlBuilder::class)))
        ->toThrow(ConnectionException::class);
})->with([
    'rate limited' => [429],
    'frontend down' => [503],
    'frontend error' => [500],
]);

it('notifies the frontend when a scheduled publish goes live', function (): void {
    Queue::fake();

    /*
     * THE CASE NO OBSERVER CAN COVER. An elapsing embargo is not a model write, so
     * FrontendWebhookObserver never fires — and without the dispatch inside
     * cms:publish-due the frontend would keep serving its cached page until its own
     * max-age expired. The published-on-a-timer problem would move from the API to the
     * frontend rather than being fixed.
     */
    $content = Content::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => now()->addMinutes(5),
    ]);

    Queue::assertPushed(NotifyFrontendOfChange::class, 1); // the save itself

    Queue::fake();

    $this->travelTo(now()->addMinutes(6));

    artisan('cms:publish-due')->assertSuccessful();

    // Named 'published' rather than 'saved', because that is what happened — the embargo
    // elapsed, and a frontend may want to treat a first publication differently from an
    // edit.
    Queue::assertPushed(
        NotifyFrontendOfChange::class,
        fn (NotifyFrontendOfChange $job): bool => $job->event === 'published'
            && $job->modelClass === Content::class
            && $job->modelKey === $content->getKey(),
    );
});

it('queues no scheduled-publish notification when webhooks are off', function (): void {
    config()->set('cms.webhooks.endpoints', null);

    Content::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => now()->subSeconds(30),
    ]);

    Queue::fake();

    artisan('cms:publish-due')->assertSuccessful();

    Queue::assertNotPushed(NotifyFrontendOfChange::class);
});
