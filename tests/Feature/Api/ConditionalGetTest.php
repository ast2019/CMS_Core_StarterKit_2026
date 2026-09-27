<?php

declare(strict_types=1);

use App\Models\Content;
use App\Models\Setting;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/**
 * Conditional GET on the Delivery API (Requirement 8.4).
 *
 * The API had a server-side cache and nothing at the HTTP layer — `no-cache, private`
 * on every response and no validator — so a frontend, a CDN and a browser all
 * re-transferred a payload they already held on every request. For a headless CMS whose
 * consumers are a Next.js server and a CDN, that is the wrong place to stop.
 */
it('gives every read an ETag and a cacheable Cache-Control', function (string $url): void {
    Content::factory()->published()->create(['slug' => ['fa' => 'خبر-آزمایشی']]);

    $response = getJson($url)->assertOk();

    expect($response->headers->get('ETag'))->toBeString()
        ->and($response->headers->get('Cache-Control'))->toContain('public')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=60');
})->with([
    // A list, a single record's SEO payload and a settings read: three different
    // controllers, so this pins the behaviour to the middleware rather than to one route.
    'list' => ['/api/v1/news?locale=fa'],
    'seo' => ['/api/v1/news/خبر-آزمایشی/seo?locale=fa'],
    'settings' => ['/api/v1/settings?locale=fa'],
]);

it('answers an unchanged ETag with 304 and no body', function (): void {
    Content::factory()->published()->create(['slug' => ['fa' => 'خبر-آزمایشی']]);

    $first = getJson('/api/v1/news?locale=fa')->assertOk();
    $etag = $first->headers->get('ETag');

    expect($etag)->toBeString();

    $second = withHeaders(['If-None-Match' => (string) $etag])
        ->getJson('/api/v1/news?locale=fa');

    // The whole point: the frontend keeps its copy and pays for a header exchange
    // instead of the payload.
    $second->assertStatus(304);

    expect($second->getContent())->toBe('')
        ->and($second->headers->get('ETag'))->toBe($etag);
});

it('sends a fresh body and a new ETag once the content changes', function (): void {
    $content = Content::factory()->published()->create([
        'slug' => ['fa' => 'خبر-آزمایشی'],
        'title' => ['fa' => 'عنوان اول'],
    ]);

    $etag = getJson('/api/v1/news?locale=fa')->assertOk()->headers->get('ETag');

    // An editor corrects the headline. The observer invalidates the server cache, so the
    // next read recomputes — and the ETag must move with it, or the frontend is pinned
    // to the old payload.
    $content->setTranslation('title', 'fa', 'عنوان دوم')->save();

    $second = withHeaders(['If-None-Match' => (string) $etag])
        ->getJson('/api/v1/news?locale=fa')
        ->assertOk();

    expect($second->headers->get('ETag'))->not->toBe($etag);

    $second->assertJsonPath('data.0.title', 'عنوان دوم');
});

it('varies the ETag by locale, because the payload does', function (): void {
    Content::factory()->published()->create([
        'slug' => ['fa' => 'خبر-آزمایشی', 'en' => 'test-news'],
        'title' => ['fa' => 'عنوان', 'en' => 'Headline'],
    ]);

    $fa = getJson('/api/v1/news?locale=fa')->assertOk()->headers->get('ETag');
    $en = getJson('/api/v1/news?locale=en')->assertOk()->headers->get('ETag');

    // Two different bodies must never share a validator, or one locale's cached copy
    // satisfies the other locale's conditional request.
    expect($fa)->not->toBe($en);
});

it('does not let a stale ETag from one locale satisfy another', function (): void {
    Content::factory()->published()->create([
        'slug' => ['fa' => 'خبر-آزمایشی', 'en' => 'test-news'],
        'title' => ['fa' => 'عنوان', 'en' => 'Headline'],
    ]);

    $fa = getJson('/api/v1/news?locale=fa')->assertOk()->headers->get('ETag');

    withHeaders(['If-None-Match' => (string) $fa])
        ->getJson('/api/v1/news?locale=en')
        ->assertOk();
});

it('leaves the contact form POST uncached and unvalidated', function (): void {
    /*
     * A write must never carry a validator or a public Cache-Control. The middleware is
     * registered on the whole delivery group, which includes this endpoint, so the
     * method guard is what keeps it out.
     */
    $response = postJson('/api/v1/contact', [
        'name' => 'آزمون',
        'email' => 'test@example.test',
        'message' => 'یک پیام آزمایشی برای بررسی رفتار کش.',
    ]);

    expect($response->headers->get('ETag'))->toBeNull()
        ->and((string) $response->headers->get('Cache-Control'))->not->toContain('public');
});

it('does not attach a validator to an error response', function (): void {
    // A 404 body is an error message, not content; caching or revalidating it as if it
    // were would serve "not found" for a record that exists by the time it is asked for.
    $response = getJson('/api/v1/news/does-not-exist?locale=fa')->assertNotFound();

    expect($response->headers->get('ETag'))->toBeNull();
});

it('refuses to let a shared cache hold a key-gated response', function (): void {
    config()->set('cms.api.delivery.require_key', true);
    config()->set('cms.api.delivery.key', 'secret-key');

    Content::factory()->published()->create(['slug' => ['fa' => 'خبر-آزمایشی']]);

    $response = withHeaders(['X-API-Key' => 'secret-key'])
        ->getJson('/api/v1/news?locale=fa')
        ->assertOk();

    /*
     * With a required key, responses vary per client. AuthenticateDeliveryApi runs
     * OUTSIDE this middleware on the way out, so its `private, no-store` gets the last
     * word — the ordering in routes/api.php is load-bearing, and this is the assertion
     * that would catch it being reordered.
     */
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->not->toContain('public');
});

it('honours a zero max-age as always-revalidate rather than never-cache', function (): void {
    config()->set('cms.api.delivery.http_max_age', 0);

    Content::factory()->published()->create(['slug' => ['fa' => 'خبر-آزمایشی']]);

    $response = getJson('/api/v1/news?locale=fa')->assertOk();

    // Still an ETag, so a client keeps its copy and pays for a 304 — `no-cache` means
    // "revalidate", not "do not store", despite the name.
    expect($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->headers->get('ETag'))->toBeString();

    $etag = $response->headers->get('ETag');

    withHeaders(['If-None-Match' => (string) $etag])
        ->getJson('/api/v1/news?locale=fa')
        ->assertStatus(304);
});

it('keeps the maintenance 503 out of any cache', function (): void {
    Setting::put(Setting::MAINTENANCE_MODE, true);

    $response = getJson('/api/v1/news?locale=fa')->assertStatus(503);

    expect($response->headers->get('ETag'))->toBeNull()
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});
