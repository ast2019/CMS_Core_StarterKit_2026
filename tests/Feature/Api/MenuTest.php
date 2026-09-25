<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Enums\TranslationStatus;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\getJson;

/**
 * Navigation: resolution, the tree, and the Delivery payload.
 *
 * Requirements 1.1, 3.1, 5.5, 8.3, 8.4.
 *
 * The menu subsystem had no tests at all — the only mention of MenuItem anywhere
 * under tests/ was the audit-rules model list. It was also the part of the Delivery
 * API that resolved records per locale, recursed without a bound, and ignored the
 * module toggle, so "no tests" and "several defects" were the same fact.
 */
it('resolves a linked record to the right path per type and locale', function (): void {
    $content = Content::factory()->published()->create([
        'title' => ['fa' => 'خبر نخست', 'en' => 'First story'],
    ]);
    $page = Page::factory()->create(['title' => ['fa' => 'درباره ما', 'en' => 'About us']]);
    $category = Category::factory()->create(['name' => ['fa' => 'اقتصاد', 'en' => 'Economy']]);
    $gallery = Gallery::factory()->published()->create(['title' => ['fa' => 'تصاویر', 'en' => 'Pictures']]);

    $items = [
        [$content, 'news'],
        [$page, null],
        [$category, 'category'],
        [$gallery, 'gallery'],
    ];

    foreach ($items as [$target, $segment]) {
        $item = MenuItem::factory()->pointingAt($target)->create();

        foreach (['fa', 'en'] as $locale) {
            $slug = $target->getTranslation('slug', $locale, useFallbackLocale: false);

            $expected = $segment === null
                ? "/{$locale}/{$slug}"
                : "/{$locale}/{$segment}/{$slug}";

            expect($item->resolveUrl($locale))->toBe($expected);
        }
    }
});

it('returns a raw link untouched and root-relative', function (): void {
    // A raw link is locale-blind by nature, which is exactly why linking a record
    // is preferable — but it must still come back verbatim.
    $item = MenuItem::factory()->create(['link' => 'https://example.test/external']);

    expect($item->resolveUrl('fa'))->toBe('https://example.test/external')
        ->and($item->resolveUrl('en'))->toBe('https://example.test/external');
});

it('drops an item whose target is missing, unpublished or gone', function (): void {
    $draft = Content::factory()->create(['status' => ContentStatus::Draft]);
    $scheduled = Content::factory()->scheduled()->create();
    $deleted = Page::factory()->create();

    $draftItem = MenuItem::factory()->pointingAt($draft)->create();
    $scheduledItem = MenuItem::factory()->pointingAt($scheduled)->create();
    $danglingItem = MenuItem::factory()->pointingAt($deleted)->create();

    $deleted->forceDelete();

    expect($draftItem->resolveUrl('fa'))->toBeNull()
        ->and($scheduledItem->resolveUrl('fa'))->toBeNull()
        ->and($danglingItem->fresh()?->resolveUrl('fa'))->toBeNull();
});

it('drops an item pointing into a disabled module', function (): void {
    /*
     * Requirement 1.1. With the gallery module off, /api/v1/galleries/{slug} answers
     * 404, so a gallery link is a link to nothing — the menu must not outlive the
     * feature it points at.
     */
    $gallery = Gallery::factory()->published()->create();
    $item = MenuItem::factory()->pointingAt($gallery)->create();

    expect($item->resolveUrl('fa'))->not->toBeNull();

    config()->set('cms.modules.gallery', false);

    expect($item->resolveUrl('fa'))->toBeNull();
});

it('prefers the relation over a raw link and clears the loser on save', function (): void {
    /*
     * resolveUrl() used to check `link` first, so re-pointing a raw-URL item at a
     * page appeared to do nothing: Filament does not dehydrate the hidden field, so
     * the stale link survived the save and kept winning.
     */
    $page = Page::factory()->create();

    $item = MenuItem::factory()->create(['link' => '/fa/hardcoded']);
    $item->linkable_type = $page::class;
    $item->linkable_id = $page->getKey();
    $item->save();

    expect($item->fresh()?->link)->toBeNull()
        ->and($item->resolveUrl('fa'))->toBe('/fa/'.$page->getTranslation('slug', 'fa'));
});

it('refuses to save an item with neither a link nor a target', function (): void {
    // Such an item saved cleanly before and then simply never appeared in the API,
    // which from the panel looks like a caching bug.
    expect(fn () => MenuItem::query()->create([
        'label' => ['fa' => 'بیمقصد'],
        'menu_key' => 'header',
    ]))->toThrow(ValidationException::class);

    expect(MenuItem::query()->count())->toBe(0);
});

it('drops a half-written morph rather than storing a type that points at nothing', function (): void {
    $item = MenuItem::factory()->create(['link' => '/fa/keep-me']);

    $item->linkable_type = Page::class;
    $item->linkable_id = null;
    $item->save();

    expect($item->fresh()?->linkable_type)->toBeNull()
        ->and($item->fresh()?->link)->toBe('/fa/keep-me');
});

it('serves a menu as an ordered, nested tree', function (): void {
    $page = Page::factory()->create(['title' => ['fa' => 'درباره ما']]);

    $second = MenuItem::factory()->create(['label' => ['fa' => 'دوم'], 'position' => 2]);
    $first = MenuItem::factory()->pointingAt($page)->create(['label' => ['fa' => 'اول'], 'position' => 1]);
    $child = MenuItem::factory()->childOf($second)->create(['label' => ['fa' => 'زیرمنو']]);

    // Another menu entirely; it must not leak into the header payload.
    MenuItem::factory()->inMenu('footer')->create(['label' => ['fa' => 'پانوشت']]);

    $response = getJson('/api/v1/menus/header')->assertOk();

    expect($response->json('meta.menu'))->toBe('header')
        ->and($response->json('meta.locale'))->toBe('fa')
        ->and(array_column($response->json('data'), 'label'))->toBe(['اول', 'دوم'])
        ->and($response->json('data.0.url'))->toBe('/fa/'.$page->getTranslation('slug', 'fa'))
        ->and($response->json('data.0.children'))->toBe([])
        ->and($response->json('data.1.children.0.id'))->toBe($child->id)
        ->and($response->json('data.1.children.0.label'))->toBe('زیرمنو');
});

it('flags an item resolved through the source locale instead of hiding it', function (): void {
    /*
     * Requirement 5.5 — no SILENT fallback. Navigation deliberately keeps the item
     * (a menu that empties itself in every locale but Persian is a broken site, and
     * the Delivery API resolves a source-locale slug under any locale), but it now
     * says so in the same three fields the content resources use, so a frontend that
     * wants a strictly-translated menu can drop the item itself.
     */
    $page = Page::factory()->create(['title' => ['fa' => 'درباره ما']]);
    $item = MenuItem::factory()->pointingAt($page)->create(['label' => ['fa' => 'درباره']]);

    $response = getJson('/api/v1/menus/header?locale=en')->assertOk();

    expect($response->json('data.0.id'))->toBe($item->id)
        ->and($response->json('data.0.url'))->toBe('/en/'.$page->getTranslation('slug', 'fa'))
        ->and($response->json('data.0.meta.is_fallback'))->toBeTrue()
        ->and($response->json('data.0.meta.fallback_locale'))->toBe('fa')
        ->and($response->json('data.0.meta.translation_status'))
        ->toBe(TranslationStatus::NotTranslated->value);
});

it('does not flag an item that really is translated', function (): void {
    $item = MenuItem::factory()->create(['label' => ['fa' => 'خانه', 'en' => 'Home']]);

    $response = getJson('/api/v1/menus/header?locale=en')->assertOk();

    expect($response->json('data.0.label'))->toBe('Home')
        ->and($response->json('data.0.meta.is_fallback'))->toBeFalse()
        ->and($response->json('data.0.meta.fallback_locale'))->toBeNull()
        ->and($item->resolveUrl('en'))->toBe($item->link);
});

it('omits an item that resolves to nothing but keeps a parent that has children', function (): void {
    $draft = Content::factory()->create(['status' => ContentStatus::Draft]);

    MenuItem::factory()->pointingAt($draft)->create(['label' => ['fa' => 'پیشنویس'], 'position' => 1]);

    $heading = MenuItem::factory()->pointingAt($draft)->create(['label' => ['fa' => 'سرفصل'], 'position' => 2]);
    MenuItem::factory()->childOf($heading)->create(['label' => ['fa' => 'زیرمورد']]);

    $response = getJson('/api/v1/menus/header')->assertOk();

    // The bare unresolvable item is gone; the section heading survives because its
    // children are still reachable.
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.label'))->toBe('سرفصل')
        ->and($response->json('data.0.url'))->toBeNull()
        ->and($response->json('data.0.children'))->toHaveCount(1);
});

it('caps the tree at the configured depth', function (): void {
    /*
     * The renderer used to recurse into children with no bound at all while only two
     * levels were eager-loaded, so a fourth level was both unbounded and an N+1. The
     * cap is enforced in the payload as well as in the form, because the form is not
     * the only way rows are written.
     */
    $level1 = MenuItem::factory()->create(['label' => ['fa' => 'سطح ۱']]);
    $level2 = MenuItem::factory()->childOf($level1)->create(['label' => ['fa' => 'سطح ۲']]);
    $level3 = MenuItem::factory()->childOf($level2)->create(['label' => ['fa' => 'سطح ۳']]);
    MenuItem::factory()->childOf($level3)->create(['label' => ['fa' => 'سطح ۴']]);

    $response = getJson('/api/v1/menus/header')->assertOk();

    expect(MenuItem::MAX_DEPTH)->toBe(3)
        ->and($response->json('data.0.children.0.children.0.label'))->toBe('سطح ۳')
        // The fourth level is not rendered, and nothing beyond it was queried.
        ->and($response->json('data.0.children.0.children.0.children'))->toBe([]);
});

it('renders a full tree in a bounded number of queries', function (): void {
    $pages = Page::factory()->count(3)->create();

    $level1 = MenuItem::factory()->pointingAt($pages[0])->create();
    $level2 = MenuItem::factory()->childOf($level1)->pointingAt($pages[1])->create();
    MenuItem::factory()->childOf($level2)->pointingAt($pages[2])->create();

    DB::enableQueryLog();

    getJson('/api/v1/menus/header')->assertOk();

    $queries = count(DB::getQueryLog());

    DB::disableQueryLog();

    /*
     * Eager loads: the roots, then children + linkable per level. The point is that
     * the count does not grow with the number of items, which it did while only two
     * levels were loaded and the renderer recursed freely.
     */
    expect($queries)->toBeLessThanOrEqual(12);
});

it('serves no menu when the navigation module is switched off', function (): void {
    MenuItem::factory()->create();

    getJson('/api/v1/menus/header')->assertOk();

    config()->set('cms.modules.menu', false);

    // 404, not 403: the module does not exist on this site, and "forbidden" would
    // advertise that there is something to get access to.
    getJson('/api/v1/menus/header')->assertNotFound();
});

it('answers an unknown menu key with an empty list', function (): void {
    // Documented, not fixed here: menu keys are not a validated set yet, so a 404
    // would have to be based on a hardcoded list. The menu LOCATION concept is
    // separate work.
    MenuItem::factory()->create();

    getJson('/api/v1/menus/nosuchmenu')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.menu', 'nosuchmenu');
});

it('refreshes the menu when a linked record changes its slug', function (): void {
    // Requirement 8.4 — MenuItem is tagged NAVIGATION and the menu is also tagged
    // CONTENT, so a target's slug change busts the navigation cache too.
    $page = Page::factory()->create(['title' => ['fa' => 'درباره ما']]);
    MenuItem::factory()->pointingAt($page)->create();

    getJson('/api/v1/menus/header')
        ->assertJsonPath('data.0.url', '/fa/'.$page->getTranslation('slug', 'fa'));

    $page->setTranslation('slug', 'fa', 'about-us-renamed');
    $page->save();

    getJson('/api/v1/menus/header')
        ->assertJsonPath('data.0.url', '/fa/about-us-renamed');
});

it('walks a cyclic tree without hanging', function (): void {
    /*
     * A cycle is no longer creatable in the panel, but rows written before that
     * validation existed — or by a bad import — must not turn a menu render into an
     * infinite loop. Both walks carry a depth guard, as Category::ancestors() does.
     */
    $a = MenuItem::factory()->create();
    $b = MenuItem::factory()->childOf($a)->create();

    DB::table('menu_items')->where('id', $a->getKey())->update(['parent_id' => $b->getKey()]);

    $a->refresh();

    expect($a->ancestors())->not->toBeEmpty()
        ->and($a->level())->toBeGreaterThan(1)
        ->and($a->subtreeHeight())->toBeGreaterThan(1)
        // Neither row is top-level any more, so the branch is simply absent —
        // which is the failure mode the form validation now prevents up front.
        ->and(getJson('/api/v1/menus/header')->assertOk()->json('data'))->toBe([]);
});

it('knows which nestings are legal and why', function (): void {
    /*
     * The rule the form validates, tested directly: the form reports it, but the
     * decision belongs to the model so a future Management API cannot get a
     * different answer.
     */
    $root = MenuItem::factory()->create();
    $child = MenuItem::factory()->childOf($root)->create();
    $grandchild = MenuItem::factory()->childOf($child)->create();

    $loose = MenuItem::factory()->create();
    $looseChild = MenuItem::factory()->childOf($loose)->create();

    expect($root->canNestUnder($child)['reason'])->toBe('cycle')
        ->and($root->canNestUnder($root)['reason'])->toBe('cycle')
        // A leaf under a level-2 item is level 3, which is the limit.
        ->and($looseChild->canNestUnder($child))->toBe(['ok' => true, 'reason' => null])
        // ...but the same move for a two-level branch would push its child to 4.
        ->and($loose->canNestUnder($child)['reason'])->toBe('depth')
        ->and($loose->canNestUnder($grandchild)['reason'])->toBe('depth')
        ->and($loose->canNestUnder($root))->toBe(['ok' => true, 'reason' => null]);
});
