<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Enums\MediaRole;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Setting;

use function Pest\Laravel\getJson;

/**
 * The SEO payload, for everything with a public URL (Requirements 7.1–7.3).
 *
 * It served articles only, which made the promise in SeoController's docblock — that a
 * client site never re-implements schema assembly — true of articles alone. A frontend
 * rendering the homepage, a static page, a category archive or a gallery got three meta
 * strings and had to build canonical, hreflang, Open Graph and JSON-LD itself. On a site
 * where the homepage and the section archives carry the most links, that was missing
 * from the pages that matter most.
 */
beforeEach(function (): void {
    Setting::put(Setting::SITE_NAME, ['fa' => 'خبرگزاری نمونه'], isTranslatable: true);
});

/**
 * The keys every type must answer with, whatever it is.
 *
 * One shape for all five endpoints is the point: a frontend should not need a different
 * reader per content type to put a title in a tag.
 *
 * @return array<string, mixed>
 */
function seoPayloadStructure(): array
{
    return ['data' => [
        'canonical',
        'meta' => ['title', 'description', 'robots'],
        'open_graph',
        'twitter',
        'hreflang',
        'json_ld' => ['@context', '@graph'],
        'seo_warnings',
        'seo_analysis' => ['keyphrase', 'score', 'band', 'checks'],
    ]];
}

/**
 * One record of every routable type, with the slugs the datasets above ask for.
 *
 * PageFactory already defaults to published, so no state is needed there.
 */
function seedEveryRoutableType(): void
{
    Content::factory()->published()->create(['slug' => ['fa' => 'مطلب-آزمایشی']]);
    Page::factory()->create(['slug' => ['fa' => 'درباره-ما']]);
    Page::factory()->create(['slug' => ['fa' => 'خانه'], 'system_key' => Page::SYSTEM_HOME]);
    Category::factory()->create(['slug' => ['fa' => 'اقتصاد']]);
    Gallery::factory()->published()->create(['slug' => ['fa' => 'نمایشگاه']]);
}

it('serves the same payload shape for every routable type', function (string $url): void {
    seedEveryRoutableType();

    getJson($url.'?locale=fa')
        ->assertOk()
        ->assertJsonStructure(seoPayloadStructure())
        ->assertJsonPath('data.json_ld.@context', 'https://schema.org')
        ->assertJsonPath('meta.locale', 'fa');
})->with([
    'article' => ['/api/v1/news/مطلب-آزمایشی/seo'],
    'page' => ['/api/v1/pages/درباره-ما/seo'],
    'category' => ['/api/v1/categories/اقتصاد/seo'],
    'gallery' => ['/api/v1/galleries/نمایشگاه/seo'],
    'homepage' => ['/api/v1/home-page/seo'],
]);

it('describes a static page as a WebPage, not an Article', function (): void {
    Page::factory()->create([
        'slug' => ['fa' => 'درباره-ما'],
        'title' => ['fa' => 'درباره ما'],
    ]);

    $types = collect(getJson('/api/v1/pages/درباره-ما/seo?locale=fa')->assertOk()->json('data.json_ld.@graph'))
        ->pluck('@type');

    /*
     * A page is not a dated piece of journalism with an author. Announcing "About us" as
     * an Article is the same class of false claim ArticleSchemaType exists to prevent.
     */
    expect($types)->toContain('WebPage')
        ->and($types)->not->toContain('Article')
        ->and($types)->not->toContain('NewsArticle');
});

it('describes a category as a CollectionPage and walks its ancestry', function (): void {
    $parent = Category::factory()->create([
        'slug' => ['fa' => 'ورزشی'],
        'name' => ['fa' => 'ورزشی'],
    ]);

    Category::factory()->create([
        'slug' => ['fa' => 'فوتبال'],
        'name' => ['fa' => 'فوتبال'],
        'parent_id' => $parent->getKey(),
    ]);

    $graph = collect(getJson('/api/v1/categories/فوتبال/seo?locale=fa')->assertOk()->json('data.json_ld.@graph'));

    expect($graph->pluck('@type'))->toContain('CollectionPage');

    $breadcrumb = $graph->firstWhere('@type', 'BreadcrumbList');

    /*
     * Home → ورزشی → فوتبال. A nested category has to announce its place in the tree; a
     * trail that jumps from the homepage straight to the leaf describes a flat site.
     */
    expect($breadcrumb)->not->toBeNull()
        ->and(collect($breadcrumb['itemListElement'])->pluck('name')->all())
        ->toBe(['خبرگزاری نمونه', 'ورزشی', 'فوتبال']);
});

it('describes a gallery as an ImageGallery and lists its images as parts', function (): void {
    $gallery = Gallery::factory()->published()->create(['slug' => ['fa' => 'نمایشگاه']]);

    $gallery->attachMediaAsset(MediaAsset::factory()->withFile()->create(), MediaRole::Gallery, position: 0);

    $graph = collect(getJson('/api/v1/galleries/نمایشگاه/seo?locale=fa')->assertOk()->json('data.json_ld.@graph'));

    $page = $graph->firstWhere('@type', 'ImageGallery');

    expect($page)->not->toBeNull()
        /*
         * hasPart, not image: `image` on a CreativeWork means "a picture OF this thing",
         * which would claim every photograph depicts the gallery itself.
         */
        ->and($page)->toHaveKey('hasPart')
        ->and($page['hasPart'][0]['@type'])->toBe('ImageObject');
});

it('describes the homepage as a WebSite with a search action', function (): void {
    Page::factory()->create([
        'slug' => ['fa' => 'خانه'],
        'system_key' => Page::SYSTEM_HOME,
    ]);

    $graph = collect(getJson('/api/v1/home-page/seo?locale=fa')->assertOk()->json('data.json_ld.@graph'));

    $website = $graph->firstWhere('@type', 'WebSite');

    expect($website)->not->toBeNull()
        ->and($website['name'])->toBe('خبرگزاری نمونه')
        // The sitelinks searchbox, and it must point at the FRONTEND's search page —
        // telling a search engine to send a human to a JSON endpoint is not that.
        ->and($website['potentialAction']['target']['urlTemplate'])
        ->toContain('/search?q={search_term_string}');
});

it('offers no search action when the search module is off', function (): void {
    // A SearchAction resolving to a 404 is worse than not offering one.
    config()->set('cms.modules.search', false);

    Page::factory()->create([
        'slug' => ['fa' => 'خانه'],
        'system_key' => Page::SYSTEM_HOME,
    ]);

    $website = collect(getJson('/api/v1/home-page/seo?locale=fa')->assertOk()->json('data.json_ld.@graph'))
        ->firstWhere('@type', 'WebSite');

    expect($website)->not->toHaveKey('potentialAction');
});

it('describes the homepage the same way through either door', function (): void {
    /*
     * The home page is reachable as /pages/{slug}/seo and as /home-page/seo. A site whose
     * root claims to be an ordinary WebPage through one door and a WebSite through the
     * other is telling a crawler two different things about the same URL.
     */
    Page::factory()->create([
        'slug' => ['fa' => 'خانه'],
        'system_key' => Page::SYSTEM_HOME,
    ]);

    $viaSlug = getJson('/api/v1/pages/خانه/seo?locale=fa')->assertOk()->json('data');
    $viaRole = getJson('/api/v1/home-page/seo?locale=fa')->assertOk()->json('data');

    expect($viaSlug)->toBe($viaRole);
});

it('404s when no homepage is designated', function (): void {
    // Designating one is optional per the handover checklist, so this must not invent a
    // payload — the same answer GET /api/v1/home-page gives.
    expect(Page::homePage())->toBeNull();

    getJson('/api/v1/home-page/seo?locale=fa')->assertNotFound();
});

it('serves a canonical for every type', function (string $url, string $expectedPath): void {
    seedEveryRoutableType();

    $canonical = getJson($url.'?locale=fa')->assertOk()->json('data.canonical');

    expect($canonical)->toBeString()
        ->and($canonical)->toContain($expectedPath);
})->with([
    'page' => ['/api/v1/pages/درباره-ما/seo', '/fa/'],
    'category' => ['/api/v1/categories/اقتصاد/seo', '/fa/category/'],
    'gallery' => ['/api/v1/galleries/نمایشگاه/seo', '/fa/gallery/'],
]);

it('404s a type whose module is switched off', function (string $module, string $url): void {
    // Requirement 1.1 — the route stays registered so the OpenAPI surface is identical on
    // every deployment, and the controller answers 404.
    seedEveryRoutableType();

    config()->set("cms.modules.{$module}", false);

    getJson($url.'?locale=fa')->assertNotFound();
})->with([
    'page' => ['page', '/api/v1/pages/درباره-ما/seo'],
    'category' => ['category', '/api/v1/categories/اقتصاد/seo'],
    'gallery' => ['gallery', '/api/v1/galleries/نمایشگاه/seo'],
]);

it('does not serve SEO for a draft', function (): void {
    Page::factory()->create([
        'slug' => ['fa' => 'پیش-نویس'],
        'status' => ContentStatus::Draft,
    ]);

    // Parity with the sibling show() route: an unpublished record has no public URL, so
    // it has no public SEO either.
    getJson('/api/v1/pages/پیش-نویس/seo?locale=fa')->assertNotFound();
});

it('falls back to the source-locale slug like the sibling show route', function (): void {
    Page::factory()->create(['slug' => ['fa' => 'درباره-ما']]);

    /*
     * An untranslated record must not be reachable through one door and absent through
     * the other: /pages/{slug} resolves a source-locale slug in any locale, so this does
     * too.
     */
    getJson('/api/v1/pages/درباره-ما/seo?locale=en')->assertOk();
});

it('omits the Open Graph image for a category, which carries no media', function (): void {
    Category::factory()->create(['slug' => ['fa' => 'اقتصاد']]);

    $openGraph = getJson('/api/v1/categories/اقتصاد/seo?locale=fa')->assertOk()->json('data.open_graph');

    /*
     * The KEY stays and its value is null, rather than the key vanishing. That is what
     * lets a frontend reader written once work across every type: it checks for a value,
     * not for the presence of a key that appears on some types and not others.
     */
    expect($openGraph)->toHaveKey('og:image')
        ->and($openGraph['og:image'])->toBeNull()
        // 'website' rather than 'article': a category archive is not a piece of writing.
        ->and($openGraph['og:type'])->toBe('website');
});
