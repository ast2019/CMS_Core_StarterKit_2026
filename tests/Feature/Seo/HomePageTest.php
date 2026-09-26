<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Slide;
use App\Services\Content\RedirectSuggestionService;
use App\Services\Seo\HreflangBuilder;
use App\Services\Seo\UrlBuilder;
use App\Services\Sitemap\SitemapGenerator;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/**
 * The homepage: one page, addressed at the locale root.
 *
 * Requirements 1.1, 3.1, 3.8, 5.2, 7.2, 7.4, 7.5.
 *
 * There was no homepage concept at all. `UrlBuilder::localeHome()` hardcoded /{locale},
 * `Page` knew only the 404 and maintenance system keys, and a menu item could not point
 * at "home" except as a raw string — so the one URL every site has was the one URL the
 * CMS could not describe. The tests below are mostly about the consequence of getting it
 * half right: a homepage reachable at both /fa and /fa/{slug} is duplicate content, and
 * that only shows up once a crawler finds it.
 */
it('addresses the homepage at the locale root rather than under its slug', function (): void {
    $home = Page::factory()->homePage()->create();
    $urls = app(UrlBuilder::class);

    expect($urls->pathFor($home, 'fa'))->toBe('/fa')
        ->and($urls->pathFor($home, 'en'))->toBe('/en')
        ->and($urls->canonicalFor($home, 'fa'))->toBe($urls->localeHome('fa'))
        // ...and an ordinary page is still addressed under its slug.
        ->and($urls->pathFor(Page::factory()->create(['title' => ['fa' => 'درباره']]), 'fa'))
        ->toStartWith('/fa/');
});

it('resolves the locale root even in a locale the homepage has no slug for', function (): void {
    /*
     * The locale root is the site's entry point, not a translated address: /ar exists
     * whether or not the homepage has an Arabic slug. Reading the slug first would make
     * the homepage unreachable and unlinkable in every locale but Persian.
     */
    $home = Page::factory()->homePage()->create(['slug' => ['fa' => 'خانه']]);

    expect($home->getTranslation('slug', 'ar', useFallbackLocale: false))->toBeEmpty()
        ->and(app(UrlBuilder::class)->pathFor($home, 'ar'))->toBe('/ar');
});

it('lets a menu item and a slide point at the homepage', function (): void {
    // The gap that made this a feature rather than a flag: a menu item could only reach
    // the homepage as a raw '/fa' string, which is locale-blind by nature.
    $home = Page::factory()->homePage()->create();

    $item = MenuItem::factory()->pointingAt($home)->create(['label' => ['fa' => 'خانه']]);
    $slide = Slide::factory()->pointingAt($home)->create();

    expect($item->resolveUrl('fa'))->toBe('/fa')
        ->and($item->resolveUrl('en'))->toBe('/en')
        ->and($slide->resolveUrl('ar'))->toBe('/ar');

    getJson('/api/v1/menus/header')
        ->assertOk()
        ->assertJsonPath('data.0.url', '/fa');
});

it('refuses a second homepage instead of letting two compete for one URL', function (): void {
    /*
     * The failure mode this designs out: two pages both claiming /fa, with whichever one
     * a query happens to return first winning. Enforced by the unique index on
     * `system_key`, and reported as a validation error by the model so a seeder or an
     * import gets an actionable message instead of an integrity violation.
     */
    $first = Page::factory()->homePage()->create();

    expect(fn () => Page::factory()->homePage()->create())
        ->toThrow(ValidationException::class);

    expect(Page::query()->where('system_key', Page::SYSTEM_HOME)->count())->toBe(1)
        ->and(Page::homePage()?->getKey())->toBe($first->getKey());
});

it('counts a trashed page as still holding the role', function (): void {
    /*
     * Because the unique index does. Without checking trashed rows the editor would be
     * told the role is free, and the save would then fail against a page they cannot see
     * anywhere in the panel.
     */
    $home = Page::factory()->homePage()->create();
    $home->delete();

    expect(Page::otherPageWithSystemKey(Page::SYSTEM_HOME))->not->toBeNull()
        ->and(fn () => Page::factory()->homePage()->create())
        ->toThrow(ValidationException::class);
});

it('does not mistake re-saving the homepage for a conflict', function (): void {
    $home = Page::factory()->homePage()->create();

    $home->setTranslation('title', 'fa', 'خانهٔ تازه');
    $home->save();

    expect($home->fresh()?->isHomePage())->toBeTrue();
});

it('lets the homepage role be handed over once it is cleared', function (): void {
    // Two saves rather than one, deliberately: silently stealing the role would un-home
    // a page the editor never opened.
    $first = Page::factory()->homePage()->create();
    $second = Page::factory()->create();

    $first->update(['system_key' => null]);
    $second->update(['system_key' => Page::SYSTEM_HOME]);

    expect(Page::homePage()?->getKey())->toBe($second->getKey());
});

it('does not disturb the 404 page when a homepage is designated', function (): void {
    // Requirement 3.8 — both are system pages, and `system_key` is unique per KEY, not
    // per table.
    $notFound = Page::factory()->notFoundPage()->create();
    $home = Page::factory()->homePage()->create();

    expect(Page::notFoundPage()?->getKey())->toBe($notFound->getKey())
        ->and(Page::homePage()?->getKey())->toBe($home->getKey())
        ->and($notFound->isHomePage())->toBeFalse();
});

it('serves the homepage over the delivery API', function (): void {
    $home = Page::factory()->homePage()->create();

    getJson('/api/v1/home-page')
        ->assertOk()
        ->assertJsonPath('data.id', $home->id)
        ->assertJsonPath('data.is_homepage', true)
        ->assertJsonPath('data.system_key', Page::SYSTEM_HOME)
        ->assertJsonPath('meta.locale', 'fa');
});

it('answers 404 when no homepage is designated', function (): void {
    // The concept is opt-in: a site that never designates one behaves exactly as it did
    // before, with the frontend rendering its own root.
    Page::factory()->create();

    getJson('/api/v1/home-page')->assertNotFound();
});

it('stops serving the homepage when it is unpublished', function (): void {
    /*
     * isLive() is checked by the ENDPOINT, not by Page::homePage(). The model answers
     * "which record owns /fa", which governs URL shape and must not flicker while the
     * page is unpublished for an edit; whether it may be served is a separate question.
     */
    $home = Page::factory()->homePage()->create();

    getJson('/api/v1/home-page')->assertOk();

    $home->update(['status' => ContentStatus::Draft]);

    getJson('/api/v1/home-page')->assertNotFound();

    // ...while the URL shape is unchanged, so the menu pointing at it does not move.
    expect(app(UrlBuilder::class)->pathFor($home->fresh(), 'fa'))->toBe('/fa');
});

it('serves no homepage when the page module is switched off', function (): void {
    // Requirement 1.1.
    Page::factory()->homePage()->create();

    getJson('/api/v1/home-page')->assertOk();

    config()->set('cms.modules.page', false);

    getJson('/api/v1/home-page')->assertNotFound();
});

it('flags the homepage when it is fetched by slug, so the frontend can canonicalise', function (): void {
    /*
     * `pages/{slug}` still resolves the homepage on purpose — a frontend built before
     * this concept existed keeps working. `is_homepage` is what lets it notice and
     * redirect /fa/{slug} to /fa instead of serving the same content at two URLs.
     */
    $home = Page::factory()->homePage()->create(['slug' => ['fa' => 'خانه']]);

    getJson('/api/v1/pages/'.$home->getTranslation('slug', 'fa'))
        ->assertOk()
        ->assertJsonPath('data.is_homepage', true);
});

it('lists the locale root exactly once in the sitemap', function (): void {
    /*
     * The duplicate-content bug, caught where it would appear. The homepage now
     * contributes /fa itself, with a real lastmod and reciprocal hreflang alternates, so
     * the generator must stop adding its synthetic root entry — Spatie's Sitemap
     * de-duplicates by URL and keeps the FIRST occurrence, which would have silently
     * stripped the homepage's hreflang cluster.
     */
    Page::factory()->homePage()->create([
        'title' => ['fa' => 'خانه', 'en' => 'Home'],
    ]);

    $xml = app(SitemapGenerator::class)->forLocale('fa')->render();

    expect(substr_count($xml, '<loc>'.app(UrlBuilder::class)->localeHome('fa').'</loc>'))->toBe(1);
});

it('keeps the locale root in the sitemap when no homepage record covers it', function (): void {
    // A locale sitemap that omits its own root is a crawl dead end, so the synthetic
    // entry has to survive three cases: no homepage, an unpublished one, and one with no
    // reviewed translation for the locale (Decision D-5).
    $root = '<loc>'.app(UrlBuilder::class)->localeHome('fa').'</loc>';

    expect(app(SitemapGenerator::class)->forLocale('fa')->render())->toContain($root);

    $home = Page::factory()->homePage()->create();
    $home->update(['status' => ContentStatus::Draft]);

    expect(app(SitemapGenerator::class)->forLocale('fa')->render())->toContain($root);

    // English has no reviewed translation, so the record is not eligible there.
    expect(app(SitemapGenerator::class)->forLocale('en')->render())
        ->toContain('<loc>'.app(UrlBuilder::class)->localeHome('en').'</loc>');
});

it('gives the synthetic locale root a lastmod of its own', function (): void {
    /*
     * The fallback root entry had no lastmod at all, which made the one URL every
     * crawler starts from the one with nothing to say about its freshness. It stands in
     * for the whole locale rather than for a record, so the locale's own high-water mark
     * is the honest answer — and it must still be there when the entry is synthetic.
     */
    Content::factory()->published()->create();

    // No homepage record, so the synthetic entry is the one covering the root.
    $xml = app(SitemapGenerator::class)->forLocale('fa')->render();

    preg_match_all('#<url>(.*?)</url>#s', $xml, $blocks);

    $rootBlock = null;

    foreach ($blocks[1] as $block) {
        if (str_contains($block, '<loc>'.app(UrlBuilder::class)->localeHome('fa').'</loc>')) {
            $rootBlock = $block;

            break;
        }
    }

    expect($rootBlock)->not->toBeNull()
        ->and($rootBlock)->toContain('<lastmod>');
});

it('never advertises the homepage under its slug', function (): void {
    $home = Page::factory()->homePage()->create(['slug' => ['fa' => 'khaneh']]);

    $xml = app(SitemapGenerator::class)->forLocale('fa')->render();

    expect($xml)->not->toContain('/fa/khaneh')
        ->and(app(HreflangBuilder::class)->for($home))
        ->not->toContain(app(UrlBuilder::class)->absolute('/fa/khaneh'));
});

it('suggests no redirect when the homepage slug changes', function (): void {
    /*
     * Because no public URL changed: the homepage's slug is not part of its address. The
     * class-only path builder would have offered a 301 from /fa/old-slug — a URL that was
     * never reachable — and a redirect table full of those is how the engine loses an
     * editor's trust.
     */
    $home = Page::factory()->homePage()->create(['slug' => ['fa' => 'old-home']]);
    $before = $home->getTranslations('slug');

    $home->setTranslation('slug', 'fa', 'new-home');
    $home->save();

    expect(app(RedirectSuggestionService::class)->pendingFor($home, $before))->toBeEmpty()
        ->and(Redirect::query()->count())->toBe(0);
});

it('still suggests a redirect for an ordinary page', function (): void {
    // The control for the test above: the homepage is the exception, not the new rule.
    $page = Page::factory()->create(['slug' => ['fa' => 'old-about']]);
    $before = $page->getTranslations('slug');

    $page->setTranslation('slug', 'fa', 'new-about');
    $page->save();

    $pending = app(RedirectSuggestionService::class)->pendingFor($page, $before);

    expect($pending)->toHaveKey('fa')
        ->and($pending['fa']['from'])->toBe('/fa/old-about')
        ->and($pending['fa']['to'])->toBe('/fa/new-about');
});

it('keeps the panel reachable at the root redirect', function (): void {
    // Sanity: this Core is headless, so `/` on THIS host still points at the panel. The
    // homepage concept describes the FRONTEND's root, not this one.
    get('/')->assertRedirect(config('cms.brand.panel_path'));
});
