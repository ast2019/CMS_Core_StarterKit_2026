<?php

declare(strict_types=1);

use App\Enums\RedirectType;
use App\Models\Content;
use App\Models\Redirect;
use App\Services\Content\RedirectSuggestionService;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/**
 * Requirement 7.5.
 */
it('redirects a stored path with its configured status code', function (): void {
    Redirect::query()->create([
        'from_path' => '/fa/old-path',
        'to_path' => '/fa/news/new-path',
        'type' => RedirectType::Permanent,
    ]);

    get('/fa/old-path')
        ->assertStatus(301)
        ->assertRedirect('/fa/news/new-path');
});

it('honours a temporary redirect', function (): void {
    Redirect::query()->create([
        'from_path' => '/fa/campaign',
        'to_path' => '/fa/news/launch',
        'type' => RedirectType::Temporary,
    ]);

    get('/fa/campaign')->assertStatus(302);
});

it('normalises the stored path so a trailing slash still matches', function (): void {
    // Editors paste inconsistently; a redirect that works for /x but not /x/ is a
    // support ticket waiting to happen.
    Redirect::query()->create([
        'from_path' => 'https://example.test/fa/pasted-full-url/',
        'to_path' => '/fa/news/target',
        'type' => RedirectType::Permanent,
    ]);

    $redirect = Redirect::query()->firstOrFail();

    // The host is stripped: storing it would break every redirect on a domain change.
    expect($redirect->from_path)->toBe('/fa/pasted-full-url');

    get('/fa/pasted-full-url')->assertStatus(301);
});

it('collapses a chain into a single hop', function (): void {
    /*
     * Chains happen legitimately when a slug changes twice. Each hop costs the
     * visitor a round trip and crawlers stop following after a few, so the chain is
     * resolved server-side and the visitor is sent straight to the end.
     */
    Redirect::query()->create(['from_path' => '/fa/a', 'to_path' => '/fa/b', 'type' => RedirectType::Permanent]);
    Redirect::query()->create(['from_path' => '/fa/b', 'to_path' => '/fa/c', 'type' => RedirectType::Permanent]);
    Redirect::query()->create(['from_path' => '/fa/c', 'to_path' => '/fa/final', 'type' => RedirectType::Permanent]);

    get('/fa/a')
        ->assertStatus(301)
        ->assertRedirect('/fa/final');
});

it('does not loop when a cycle exists in the data', function (): void {
    /*
     * The form validation refuses a self-referential redirect, but a cycle can still
     * be assembled from two separately-valid rows. Serving the request normally is the
     * only safe outcome: following it would loop the browser, and a 500 would take
     * down a URL that might otherwise resolve.
     */
    Redirect::query()->create(['from_path' => '/fa/x', 'to_path' => '/fa/y', 'type' => RedirectType::Permanent]);
    Redirect::query()->create(['from_path' => '/fa/y', 'to_path' => '/fa/x', 'type' => RedirectType::Permanent]);

    $response = get('/fa/x');

    expect($response->status())->not->toBe(301)
        ->and($response->status())->not->toBe(302);
});

it('preserves the incoming query string', function (): void {
    // Dropping it would break campaign tracking and paginated links across every
    // renamed URL — a wide regression that is hard to attribute.
    Redirect::query()->create([
        'from_path' => '/fa/promo',
        'to_path' => '/fa/news/offer',
        'type' => RedirectType::Permanent,
    ]);

    get('/fa/promo?utm_source=newsletter&page=2')
        ->assertRedirect('/fa/news/offer?utm_source=newsletter&page=2');
});

it('does not override a query string the target already defines', function (): void {
    Redirect::query()->create([
        'from_path' => '/fa/legacy',
        'to_path' => '/fa/news/index?sort=latest',
        'type' => RedirectType::Permanent,
    ]);

    get('/fa/legacy?sort=oldest')->assertRedirect('/fa/news/index?sort=latest');
});

it('counts hits without firing model events', function (): void {
    $redirect = Redirect::query()->create([
        'from_path' => '/fa/counted',
        'to_path' => '/fa/news/target',
        'type' => RedirectType::Permanent,
    ]);

    get('/fa/counted');
    get('/fa/counted');

    $redirect->refresh();

    // Makes dead redirects visible so the table can be pruned on evidence.
    expect($redirect->hits)->toBe(2)
        ->and($redirect->last_hit_at)->not->toBeNull();
});

it('does not touch updated_at when counting a hit', function (): void {
    /*
     * The resolver counted through the Eloquent builder, which adds updated_at to every
     * UPDATE — so each visit read as an edit to the panel's concurrent-edit guard.
     */
    $redirect = Redirect::query()->create([
        'from_path' => '/fa/stat-only',
        'to_path' => '/fa/news/target',
        'type' => RedirectType::Permanent,
    ]);
    $before = $redirect->fresh()->updated_at;

    $this->travel(5)->minutes();

    get('/fa/stat-only')->assertStatus(301);
    getJson('/api/v1/redirects/resolve?from=/fa/stat-only')->assertOk();

    $redirect->refresh();

    expect($redirect->hits)->toBe(2)
        ->and($redirect->updated_at->equalTo($before))->toBeTrue();
});

it('redirects a Persian path however the browser encodes it', function (): void {
    /*
     * Browsers send non-ASCII paths percent-encoded and the request path keeps them that
     * way, while the slug-change offer stores Unicode — so every Persian redirect
     * answered 404 to a real visitor.
     */
    Redirect::query()->create([
        'from_path' => '/fa/news/عنوان-قدیم',
        'to_path' => '/fa/news/عنوان-جدید',
        'type' => RedirectType::Permanent,
    ]);

    get('/fa/news/'.rawurlencode('عنوان-قدیم'))->assertStatus(301);
    get('/fa/news/عنوان-قدیم')->assertStatus(301);
    getJson('/api/v1/redirects/resolve?from='.rawurlencode('/fa/news/'.rawurlencode('عنوان-قدیم')))->assertOk();

    expect(Redirect::query()->sole()->hits)->toBe(3);
});

it('stores a pasted percent-encoded path in the same form as a typed one', function (): void {
    // Copying a URL from the address bar gives the encoded form.
    $redirect = Redirect::query()->create([
        'from_path' => 'https://example.test/fa/'.rawurlencode('درباره-ما').'/',
        'to_path' => '/fa/about',
        'type' => RedirectType::Permanent,
    ]);

    expect($redirect->from_path)->toBe('/fa/درباره-ما')
        // %2F would split one segment into two, and invalid UTF-8 must not be stored.
        ->and(Redirect::normalisePath('/fa/a%2Fb'))->toBe('/fa/a%2Fb')
        ->and(Redirect::normalisePath('/fa/%FF'))->toBe('/fa/%FF');
});

it('still matches a row stored percent-encoded before paths were decoded', function (): void {
    // Written through the query builder, bypassing the mutator, as an old row would be.
    Redirect::query()->toBase()->insert([
        'from_path' => '/fa/'.rawurlencode('قدیمی'),
        'to_path' => '/fa/news/new',
        'type' => RedirectType::Permanent->value,
        'hits' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    get('/fa/'.rawurlencode('قدیمی'))->assertStatus(301);

    expect(Redirect::query()->sole()->hits)->toBe(1);
});

it('leaves unmatched paths alone', function (): void {
    get('/fa/no-redirect-here')->assertNotFound();
});

it('can be switched off with the module toggle', function (): void {
    // Requirement 1.1.
    Redirect::query()->create([
        'from_path' => '/fa/toggled',
        'to_path' => '/fa/news/target',
        'type' => RedirectType::Permanent,
    ]);

    config()->set('cms.modules.redirect', false);
    Redirect::forgetCache();

    get('/fa/toggled')->assertNotFound();
});

it('suggests a 301 when a published article changes slug', function (): void {
    $content = Content::factory()->published()->create(['title' => ['fa' => 'عنوان اول']]);
    $before = $content->getTranslations('slug');

    $content->setTranslation('slug', 'fa', 'عنوان-دوم');
    $content->save();

    $pending = app(RedirectSuggestionService::class)->pendingFor($content, $before);

    expect($pending)->toHaveKey('fa')
        ->and($pending['fa']['from'])->toBe('/fa/news/'.$before['fa'])
        ->and($pending['fa']['to'])->toBe('/fa/news/عنوان-دوم');

    $created = app(RedirectSuggestionService::class)->create($content, $pending);

    expect($created)->toBe(1)
        ->and(Redirect::query()->where('from_path', '/fa/news/'.$before['fa'])->exists())->toBeTrue();
});

it('does not re-suggest a redirect that already exists', function (): void {
    // Otherwise every subsequent save raises the same notification forever.
    $content = Content::factory()->published()->create(['title' => ['fa' => 'عنوان اول']]);
    $before = $content->getTranslations('slug');

    $content->setTranslation('slug', 'fa', 'عنوان-دوم');
    $content->save();

    $service = app(RedirectSuggestionService::class);
    $service->create($content, $service->pendingFor($content, $before));

    expect($service->pendingFor($content, $before))->toBeEmpty();
});

it('treats a first-time slug as no change', function (): void {
    // There was no old URL to redirect from.
    $content = Content::factory()->published()->create();

    $pending = app(RedirectSuggestionService::class)->pendingFor($content, ['fa' => null]);

    expect($pending)->toBeEmpty();
});
