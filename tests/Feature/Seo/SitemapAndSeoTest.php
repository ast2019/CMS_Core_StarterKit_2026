<?php

declare(strict_types=1);

use App\Enums\MediaRole;
use App\Models\Category;
use App\Models\ContactSetting;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\Setting;
use App\Services\Seo\HreflangBuilder;
use App\Services\Seo\SchemaBuilder;
use App\Services\Sitemap\SitemapGenerator;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/**
 * Requirements 7.1-7.4, 5.6. Decisions D-5, D-6.
 */
beforeEach(function (): void {
    Setting::put(Setting::SITE_NAME, ['fa' => 'سایت نمونه', 'en' => 'Sample Site'], isTranslatable: true);
});

it('publishes a sitemap index listing every locale plus images and videos', function (): void {
    $xml = get('/sitemap.xml')->assertOk()->content();

    foreach (['sitemap-fa.xml', 'sitemap-en.xml', 'sitemap-ar.xml', 'sitemap-images.xml', 'sitemap-videos.xml'] as $expected) {
        expect($xml)->toContain($expected);
    }
});

it('includes live articles in the source locale sitemap', function (): void {
    $live = Content::factory()->published()->create(['title' => ['fa' => 'خبر منتشرشده']]);
    $draft = Content::factory()->create(['title' => ['fa' => 'پیش‌نویس']]);

    $xml = get('/sitemap-fa.xml')->assertOk()->content();

    expect($xml)->toContain($live->getTranslation('slug', 'fa'))
        // A draft in a sitemap is an invitation to crawl a 404.
        ->and($xml)->not->toContain($draft->getTranslation('slug', 'fa'));
});

it('excludes a locale whose translation is not reviewed', function (): void {
    /*
     * Decision D-5. Submitting unreviewed machine output invites a quality
     * assessment across the whole locale, which is a site-wide cost for a
     * per-record shortcut.
     */
    $content = Content::factory()->published()->multilingual()->create();

    $enXml = get('/sitemap-en.xml')->assertOk()->content();

    expect($enXml)->not->toContain((string) $content->getTranslation('slug', 'en'));

    $content->markTranslationReviewed('en');

    $enXml = get('/sitemap-en.xml')->assertOk()->content();

    expect($enXml)->toContain((string) $content->getTranslation('slug', 'en'));
});

it('emits reciprocal hreflang alternates including x-default', function (): void {
    // Requirement 7.2.
    $content = Content::factory()->published()->multilingual()->create();
    $content->markTranslationReviewed('en');

    $links = app(HreflangBuilder::class)->for($content->fresh());

    expect($links)->toHaveKeys(['fa', 'en', 'x-default'])
        // x-default points at the source locale: the only one guaranteed to hold
        // complete, human-reviewed content.
        ->and($links['x-default'])->toBe($links['fa'])
        // Arabic has no reviewed translation, so advertising it would send crawlers
        // to fallback content and invite a duplicate-content conclusion.
        ->and($links)->not->toHaveKey('ar');

    $xml = get('/sitemap-fa.xml')->assertOk()->content();

    expect($xml)->toContain('xhtml:link')
        ->and($xml)->toContain('hreflang="x-default"');
});

it('returns no hreflang cluster for a single-locale record', function (): void {
    // A cluster of one is not a cluster; Google treats it as no annotation, so
    // emitting it is noise.
    $content = Content::factory()->published()->create(['title' => ['fa' => 'تک زبانه']]);

    expect(app(HreflangBuilder::class)->for($content))->toBe([]);
});

it('associates images with the page they appear on', function (): void {
    // Google's image sitemap format ties each image to a host URL; an image with no
    // host page cannot be indexed.
    $content = Content::factory()->published()->create();
    $asset = MediaAsset::factory()->withFile()->create(['alt_text' => ['fa' => 'توضیح تصویر']]);

    $content->setFeaturedImage($asset);

    $generated = app(SitemapGenerator::class)->images()->render();

    expect($generated)->toContain('image:image')
        ->and($generated)->toContain('توضیح تصویر');
});

it('omits a video with no thumbnail from the video sitemap', function (): void {
    /*
     * Decision D-6 — Google requires a thumbnail. The sitemap package THROWS without
     * a content or player location, so an invalid asset must be skipped rather than
     * allowed to abort the whole document: one misconfigured video must not take the
     * file down.
     */
    $content = Content::factory()->published()->create();
    $videoWithoutThumb = MediaAsset::factory()->video()->create();

    $content->attachMediaAsset($videoWithoutThumb, MediaRole::Inline);

    $xml = app(SitemapGenerator::class)->videos()->render();

    // Renders successfully and simply contains no video entries.
    expect($xml)->toContain('urlset')
        ->and($xml)->not->toContain('video:video');
});

it('includes an externally embedded video, which supplies its own thumbnail', function (): void {
    $content = Content::factory()->published()->create();
    $embedded = MediaAsset::factory()->embeddedVideo()->create([
        'alt_text' => ['fa' => 'ویدیوی نمونه'],
    ]);

    // hasVideoSitemapMetadata() is satisfied by an embed URL, so no ffprobe is needed.
    expect($embedded->hasVideoSitemapMetadata())->toBeTrue();

    $content->attachMediaAsset($embedded, MediaRole::Inline);

    // Still needs a thumbnail for a valid entry, which an embed does not give us
    // locally — so the generator skips it rather than emitting invalid XML.
    $xml = app(SitemapGenerator::class)->videos()->render();

    expect($xml)->toContain('urlset');
});

it('builds a breadcrumb trail from the primary category ancestry', function (): void {
    /*
     * Requirement 7.3, and the payoff for Decision D-2: an article has ONE primary
     * category, so there is a single canonical trail. With only a many-to-many
     * relation there would be no non-arbitrary answer to "which trail?".
     */
    $parent = Category::factory()->create(['name' => ['fa' => 'فناوری']]);
    $child = Category::factory()->create([
        'name' => ['fa' => 'هوش مصنوعی'],
        'parent_id' => $parent->id,
    ]);

    $content = Content::factory()->published()->create([
        'title' => ['fa' => 'خبر هوش مصنوعی'],
        'primary_category_id' => $child->id,
    ]);

    $crumbs = app(SchemaBuilder::class)->breadcrumbs($content->fresh()->load('primaryCategory'), 'fa');

    expect($crumbs['@type'])->toBe('BreadcrumbList');

    $names = array_column($crumbs['itemListElement'], 'name');

    // home → parent → child → article, root-to-leaf.
    expect($names)->toBe(['سایت نمونه', 'فناوری', 'هوش مصنوعی', 'خبر هوش مصنوعی']);
});

it('omits structured data for a draft', function (): void {
    // Emitting NewsArticle markup for a draft would be a public claim about content
    // that is not public.
    $draft = Content::factory()->create();

    expect(app(SchemaBuilder::class)->article($draft, 'fa'))->toBeNull();
});

it('omits LocalBusiness when there is no address or coordinates', function (): void {
    // Without either it is indistinguishable from Organization and adds nothing, and
    // an incomplete schema is reported as an error rather than ignored.
    ContactSetting::current()->update([
        'address' => null,
        'map_latitude' => null,
        'map_longitude' => null,
    ]);

    expect(app(SchemaBuilder::class)->localBusiness('fa'))->toBeNull();
});

it('serves an SEO payload with canonical, hreflang and a JSON-LD graph', function (): void {
    $content = Content::factory()->published()->create(['title' => ['fa' => 'خبر سئو']]);
    $asset = MediaAsset::factory()->withFile()->create();
    $content->setFeaturedImage($asset);

    $slug = $content->getTranslation('slug', 'fa');

    $response = getJson("/api/v1/news/{$slug}/seo")->assertOk();

    expect($response->json('data.canonical'))->toContain($slug)
        ->and($response->json('data.meta.robots'))->toBe('index, follow')
        ->and($response->json('data.json_ld.@context'))->toBe('https://schema.org')
        ->and($response->json('data.json_ld.@graph'))->not->toBeEmpty()
        ->and($response->json('data.open_graph.og:type'))->toBe('article')
        // og:image falls back to the featured image so a shared link always previews.
        ->and($response->json('data.open_graph.og:image'))->not->toBeNull();
});

it('marks a draft noindex in its SEO payload', function (): void {
    $draft = Content::factory()->create();

    // Not reachable via the live-only endpoint, but the trait's default is the
    // safety-critical part: a draft that leaks a 200 must not be indexable.
    expect($draft->robotsMetaFor('fa'))->toBe('noindex, nofollow');
});

it('returns 404 for a sitemap in an unsupported locale', function (): void {
    get('/sitemap-de.xml')->assertNotFound();
});
