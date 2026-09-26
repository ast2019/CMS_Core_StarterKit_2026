<?php

declare(strict_types=1);

use App\Enums\RedirectType;
use App\Models\Redirect;
use App\Observers\DeliveryCacheObserver;
use App\Services\Api\DeliveryCache;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;

/**
 * Redirects over the Delivery API.
 *
 * Requirements 1.1, 7.5, 8.3, 8.4, 8.7.
 *
 * The engine worked and nothing that serves visitors could reach it. HandleRedirects
 * runs on THIS host — the API and the panel — while a visitor clicking a stale link hits
 * the old URL on the separate frontend deployment, which never passes through that
 * middleware; and the middleware excludes `api/*`, so the frontend could not proxy
 * through it either. An editor renamed a published slug, accepted the 301, and the live
 * site kept returning 404.
 */
function makeRedirect(string $from, string $to, RedirectType $type = RedirectType::Permanent): Redirect
{
    return Redirect::query()->create(['from_path' => $from, 'to_path' => $to, 'type' => $type]);
}

it('resolves a path to its destination and status code', function (): void {
    makeRedirect('/fa/news/old-slug', '/fa/news/new-slug');

    getJson('/api/v1/redirects/resolve?from=/fa/news/old-slug')
        ->assertOk()
        ->assertJsonPath('data.from', '/fa/news/old-slug')
        ->assertJsonPath('data.to', '/fa/news/new-slug')
        ->assertJsonPath('data.status', 301)
        ->assertJsonPath('data.hops', 1)
        ->assertJsonPath('data.preserve_query', true);
});

it('reports a temporary redirect as a 302', function (): void {
    // The HTTP status rather than the enum name: 301 and 302 are the contract, and
    // `permanent` would make every consumer write the same mapping table.
    makeRedirect('/fa/campaign', '/fa/news/launch', RedirectType::Temporary);

    getJson('/api/v1/redirects/resolve?from=/fa/campaign')
        ->assertOk()
        ->assertJsonPath('data.status', 302);
});

it('collapses a chain to its final destination, exactly as the middleware does', function (): void {
    /*
     * The reason both callers share RedirectResolver. A frontend that stopped one hop
     * short would emit a 301 to a 301, which is precisely the crawler-budget problem
     * collapsing exists to solve — and the two hosts disagreeing about where a chain
     * ends is the kind of bug nobody reproduces.
     */
    makeRedirect('/fa/a', '/fa/b');
    makeRedirect('/fa/b', '/fa/c');
    makeRedirect('/fa/c', '/fa/final');

    getJson('/api/v1/redirects/resolve?from=/fa/a')
        ->assertOk()
        ->assertJsonPath('data.to', '/fa/final')
        // Told to the frontend so it can see `to` is not the `to_path` in the panel,
        // which otherwise looks like a bug when someone compares the two.
        ->assertJsonPath('data.hops', 3)
        ->assertJsonPath('data.status', 301);
});

it('keeps the first hop status for the whole chain', function (): void {
    // If the original move was permanent, the whole chain is permanent regardless of how
    // later hops happened to be recorded.
    makeRedirect('/fa/one', '/fa/two', RedirectType::Permanent);
    makeRedirect('/fa/two', '/fa/three', RedirectType::Temporary);

    getJson('/api/v1/redirects/resolve?from=/fa/one')
        ->assertOk()
        ->assertJsonPath('data.status', 301)
        ->assertJsonPath('data.to', '/fa/three');
});

it('refuses to hand back a rule that would loop the browser', function (): void {
    // A cycle assembled from two separately-valid rows. Reporting "no redirect" is the
    // only safe answer; exporting the raw hop would make the frontend loop.
    makeRedirect('/fa/x', '/fa/y');
    makeRedirect('/fa/y', '/fa/x');

    getJson('/api/v1/redirects/resolve?from=/fa/x')->assertNotFound();

    getJson('/api/v1/redirects')
        ->assertOk()
        ->assertJsonPath('data', [])
        // The rows are still there; they are simply not exported as rules.
        ->assertJsonPath('meta.total', 2);
});

it('matches the path even when the caller passes the full URL it received', function (): void {
    /*
     * A frontend will pass whatever it was asked for — a full URL, a query string, a
     * fragment. Paths are stored without any of those, so a literal lookup would match
     * nothing and every redirect would appear to be missing.
     */
    makeRedirect('/fa/promo', '/fa/news/offer');

    foreach ([
        'https://example.test/fa/promo',
        '/fa/promo/',
        '/fa/promo?utm_source=newsletter',
        '/fa/promo#section',
    ] as $from) {
        getJson('/api/v1/redirects/resolve?from='.urlencode($from))
            ->assertOk()
            ->assertJsonPath('data.to', '/fa/news/offer');
    }
});

it('tells the frontend when not to carry the query string across', function (): void {
    /*
     * The rule HandleRedirects applies on this host, stated in the payload so the
     * frontend applies the same one rather than inventing it: a destination that defines
     * its own query string must not have it overwritten by the visitor's.
     */
    makeRedirect('/fa/legacy', '/fa/news/index?sort=latest');

    getJson('/api/v1/redirects/resolve?from=/fa/legacy&')
        ->assertOk()
        ->assertJsonPath('data.preserve_query', false);
});

it('answers 404 rather than 200-with-null for a path that has no redirect', function (): void {
    /*
     * The honest answer, and the one the caller can act on directly: render the branded
     * 404 (Requirement 3.8). A 200 carrying {"to": null} would make every consumer write
     * the same null check, and would cache "no redirect" and "a redirect to null"
     * identically.
     */
    getJson('/api/v1/redirects/resolve?from=/fa/nothing-here')->assertNotFound();
});

it('rejects a lookup with no path instead of guessing', function (): void {
    getJson('/api/v1/redirects/resolve')->assertStatus(422);
    getJson('/api/v1/redirects/resolve?from=')->assertStatus(422);
});

it('counts a lookup as a hit so dead redirects stay prunable', function (): void {
    /*
     * Once the frontend is the thing honouring redirects, this is where nearly all real
     * traffic arrives. Without counting here, an editor pruning the table on evidence
     * would see every hit count sit at zero and conclude the rows are dead.
     */
    $redirect = makeRedirect('/fa/counted', '/fa/news/target');

    getJson('/api/v1/redirects/resolve?from=/fa/counted')->assertOk();
    getJson('/api/v1/redirects/resolve?from=/fa/counted')->assertOk();

    $redirect->refresh();

    expect($redirect->hits)->toBe(2)
        ->and($redirect->last_hit_at)->not->toBeNull();
});

it('exports the whole table for a build-time consumer', function (): void {
    /*
     * The second endpoint, for a statically-exported frontend that compiles redirects
     * into its own config (next.config.js redirects, a Netlify _redirects file). Such a
     * site has no request-time hook to call `resolve` from at all.
     */
    makeRedirect('/fa/one', '/fa/news/one');
    makeRedirect('/fa/two', '/fa/news/two');

    $response = getJson('/api/v1/redirects')->assertOk();

    expect($response->json('meta.total'))->toBe(2)
        ->and(array_column($response->json('data'), 'from'))->toBe(['/fa/one', '/fa/two'])
        ->and($response->json('data.0.status'))->toBe(301);
});

it('collapses chains in the export too', function (): void {
    // Or a build-time consumer would compile a redirect chain into its config and serve
    // the multi-hop version the middleware avoids.
    makeRedirect('/fa/a', '/fa/b');
    makeRedirect('/fa/b', '/fa/final');

    $response = getJson('/api/v1/redirects')->assertOk();

    $byFrom = collect($response->json('data'))->keyBy('from');

    expect($byFrom['/fa/a']['to'])->toBe('/fa/final')
        ->and($byFrom['/fa/a']['hops'])->toBe(2)
        ->and($byFrom['/fa/b']['to'])->toBe('/fa/final');
});

it('bounds the export page size', function (): void {
    // An unbounded export is the same denial-of-service shape `per_page` prevents
    // elsewhere: the table grows by one row per slug change per locale, with no ceiling.
    foreach (range(1, 12) as $n) {
        makeRedirect("/fa/p{$n}", "/fa/news/p{$n}");
    }

    getJson('/api/v1/redirects?per_page=5')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.last_page', 3);

    getJson('/api/v1/redirects?per_page=100000')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 500);
});

it('paginates in a stable order', function (): void {
    // Ordering by from_path would reshuffle pages as new redirects are inserted, and a
    // consumer walking page by page would silently miss rows.
    $created = collect(['/fa/zebra', '/fa/alpha', '/fa/mango'])
        ->map(fn (string $from): Redirect => makeRedirect($from, '/fa/news/target-'.ltrim($from, '/fa/')));

    $exported = collect(getJson('/api/v1/redirects')->assertOk()->json('data'))
        ->pluck('from')
        ->all();

    expect($exported)->toBe($created->pluck('from_path')->all());
});

it('is switched off with the redirect module', function (): void {
    // Requirement 1.1 — 404 rather than 403: the module does not exist on this site.
    makeRedirect('/fa/toggled', '/fa/news/target');

    getJson('/api/v1/redirects/resolve?from=/fa/toggled')->assertOk();
    getJson('/api/v1/redirects')->assertOk();

    config()->set('cms.modules.redirect', false);

    getJson('/api/v1/redirects/resolve?from=/fa/toggled')->assertNotFound();
    getJson('/api/v1/redirects')->assertNotFound();
});

it('gets its own rate limiter, not the shared read allowance', function (): void {
    /*
     * Requirement 8.7. The frontend calls the lookup on every 404 IT serves, from one
     * server IP for the whole site's traffic — so under the shared 120/min read limit a
     * crawler walking a few stale URLs would throttle every real visitor, and what breaks
     * is redirect handling: exactly the gap this endpoint closes.
     */
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->uri() === 'api/v1/redirects/resolve');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:cms-redirects')
        ->and($route->gatherMiddleware())->not->toContain('throttle:cms-delivery')
        ->and((int) config('cms.api.delivery.redirect_rate_limit'))
        ->toBeGreaterThan((int) config('cms.api.delivery.rate_limit'));
});

it('refreshes the exported table when a redirect is created', function (): void {
    /*
     * Requirement 8.4. The export is cached, so a new 301 has to bust it — otherwise a
     * build-time consumer keeps 404ing the URL the editor just fixed. TAG_REDIRECT is its
     * own tag rather than TAG_CONTENT so that accepting a slug-change suggestion does not
     * discard every cached article listing.
     */
    makeRedirect('/fa/first', '/fa/news/first');

    getJson('/api/v1/redirects')->assertOk()->assertJsonPath('meta.total', 1);

    makeRedirect('/fa/second', '/fa/news/second');

    getJson('/api/v1/redirects')->assertOk()->assertJsonPath('meta.total', 2);

    expect(DeliveryCacheObserver::MODEL_TAGS[Redirect::class])
        ->toBe([DeliveryCache::TAG_REDIRECT]);
});

it('never leaks operational data about a redirect', function (): void {
    // Who created a redirect and how often it fires is nobody's business on a public
    // endpoint, and a hit count is a cheap traffic oracle.
    $redirect = makeRedirect('/fa/private', '/fa/news/target');
    $redirect->forceFill(['hits' => 99])->saveQuietly();

    $payload = getJson('/api/v1/redirects')->assertOk()->json('data.0');

    expect(array_keys($payload))->toBe(['from', 'to', 'status', 'hops', 'preserve_query']);
});

it('still redirects traffic that arrives on this host', function (): void {
    // The middleware was not replaced, only re-pointed at the shared resolver: bookmarks,
    // an older single-host deployment and crawlers that learned these URLs before the
    // split all still arrive here.
    makeRedirect('/fa/legacy-host', '/fa/news/target');

    Pest\Laravel\get('/fa/legacy-host?utm_source=x')
        ->assertStatus(301)
        ->assertRedirect('/fa/news/target?utm_source=x');
});

it('leaves robots.txt and the sitemaps out of the redirect engine', function (): void {
    /*
     * Both are machine-facing documents served from this host, and a content-level
     * redirect matching one of them would take the site's crawl instructions offline —
     * with the redirect table itself only reachable through the panel.
     */
    makeRedirect('/robots.txt', '/fa/news/oops');
    makeRedirect('/sitemap.xml', '/fa/news/oops');

    Pest\Laravel\get('/robots.txt')->assertOk();
    Pest\Laravel\get('/sitemap.xml')->assertOk();
});
