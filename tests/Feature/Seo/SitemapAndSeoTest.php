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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

it('answers each FAQ question with its own section and omits a question with no prose', function (): void {
    /*
     * The whole point of FAQPage markup is that each Question is answered by what
     * the page actually says under that heading. This builder used to compute ONE
     * answer — answer_paragraph, or failing that the entire plain-text body — and
     * hand the same string to every Question, so a three-question article claimed
     * three different questions were all answered by the whole article. That is the
     * misrepresentation Google's structured-data policy penalises, and it defeats
     * the rich result it was trying to earn.
     */
    $content = Content::factory()->published()->create([
        'title' => ['fa' => 'پرسش‌های متداول'],
        // Present, and deliberately NOT reused as an answer: it is the article's
        // single direct answer for GEO, not the answer to every heading.
        'answer_paragraph' => ['fa' => 'پاسخ کوتاه کلی مقاله.'],
    ]);

    $content->setTranslation('body', 'fa', [
        'type' => 'doc',
        'content' => [
            // Prose before the first heading belongs to no question.
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'مقدمه']]],
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'ثبت‌نام چگونه است؟']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'از طریق فرم آنلاین ثبت‌نام کنید.']]],
            // Latin '?' as well as Arabic '؟', depending on the editor's keyboard.
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'هزینه چقدر است?']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'هزینه ثبت‌نام صد هزار تومان است.']]],
            // A callout inside a section is part of that answer, and is reached
            // through the same custom-block accessors search indexing uses.
            [
                'type' => 'customBlock',
                'attrs' => [
                    'config' => ['tone' => 'info', 'title' => '', 'body' => 'پرداخت فقط آنلاین است.'],
                    'id' => 'callout',
                ],
            ],
            // A nested container: its text must be counted ONCE, not once for the
            // list and again for each item inside it.
            [
                'type' => 'bulletList',
                'content' => [
                    [
                        'type' => 'listItem',
                        'content' => [
                            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'کارت بانکی']]],
                        ],
                    ],
                ],
            ],
            // A question heading that ends the document with no prose under it.
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'انصراف چگونه است؟']]],
        ],
    ]);
    $content->save();

    $faq = app(SchemaBuilder::class)->faqPage($content->fresh(), 'fa');

    expect($faq['@type'])->toBe('FAQPage');

    $names = array_column($faq['mainEntity'], 'name');
    $answers = array_map(fn (array $q): string => $q['acceptedAnswer']['text'], $faq['mainEntity']);

    // The third question has no prose beneath it, so it is OMITTED rather than
    // answered with unrelated text — a missing Question costs one rich result, a
    // wrong one risks the page's whole markup being distrusted.
    expect($names)->toBe(['ثبت‌نام چگونه است؟', 'هزینه چقدر است?'])
        // The defect in one assertion: the answers must not be the same string.
        ->and($answers[0])->not->toBe($answers[1])
        ->and($answers[0])->toBe('از طریق فرم آنلاین ثبت‌نام کنید.')
        // Second section: its paragraph, the callout prose and the list that follow
        // it, each counted exactly once.
        ->and($answers[1])->toBe('هزینه ثبت‌نام صد هزار تومان است. پرداخت فقط آنلاین است. کارت بانکی')
        ->and(substr_count($answers[1], 'کارت بانکی'))->toBe(1)
        // No answer swallows the whole article, the preamble, or answer_paragraph.
        ->and($answers[0])->not->toContain('مقدمه')
        ->and($answers[0])->not->toContain('هزینه')
        ->and($answers[1])->not->toContain('ثبت‌نام کنید')
        ->and(implode(' ', $answers))->not->toContain('پاسخ کوتاه کلی مقاله');
});

it('omits an FAQPage when fewer than two questions survive the pairing', function (): void {
    // Two question headings, only one of which has prose. A one-entry FAQPage is
    // not an FAQ, and Google is liable to read it as markup spam.
    $content = Content::factory()->published()->create(['title' => ['fa' => 'یک پرسش']]);

    $content->setTranslation('body', 'fa', [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'پرسش اول؟']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'پاسخ اول.']]],
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'پرسش دوم؟']]],
        ],
    ]);
    $content->save();

    expect(app(SchemaBuilder::class)->faqPage($content->fresh(), 'fa'))->toBeNull();
});

it('cross-references the JSON-LD graph nodes by @id', function (): void {
    /*
     * The Delivery API promises a @graph "so search engines resolve cross-references
     * between the nodes". That was not true of what it served: Article,
     * BreadcrumbList, Organization and FAQPage were assembled side by side with no
     * @id on any of them, so nothing referenced anything and the graph was four
     * loose objects in a list.
     */
    $category = Category::factory()->create(['name' => ['fa' => 'رویدادها']]);
    $content = Content::factory()->published()->create([
        'title' => ['fa' => 'خبر پیوندها'],
        'primary_category_id' => $category->id,
    ]);

    $graph = app(SchemaBuilder::class)->forArticle($content->fresh()->load('primaryCategory'), 'fa');

    $byType = [];

    foreach ($graph as $node) {
        $byType[$node['@type']] = $node;
    }

    expect($byType)->toHaveKeys(['NewsArticle', 'BreadcrumbList', 'Organization']);

    $canonical = $byType['NewsArticle']['url'];

    // Stable, fragment-based URIs built on the record's real URL, so the same
    // record yields the same URIs on every request.
    expect($byType['NewsArticle']['@id'])->toBe($canonical.'#article')
        ->and($byType['BreadcrumbList']['@id'])->toBe($canonical.'#breadcrumb')
        // The publisher is the same entity on every page of the locale, so it is
        // anchored to the locale home rather than to this article.
        ->and($byType['Organization']['@id'])->toBe($byType['Organization']['url'].'#organization');

    // The Article refers to the Organization NODE instead of repeating a name-only
    // copy of it.
    expect($byType['NewsArticle']['publisher'])->toBe(['@id' => $byType['Organization']['@id']]);

    /*
     * `breadcrumb` is a property of WebPage, not of Article, so it hangs off the
     * page node the Article declares through mainEntityOfPage — which is the page
     * itself, a different thing from the article on it.
     */
    expect($byType['NewsArticle']['mainEntityOfPage']['@id'])->toBe($canonical)
        ->and($byType['NewsArticle']['mainEntityOfPage']['@type'])->toBe('WebPage')
        ->and($byType['NewsArticle']['mainEntityOfPage']['breadcrumb'])
        ->toBe(['@id' => $byType['BreadcrumbList']['@id']]);

    // Internal consistency: every @id referenced from inside the graph resolves to
    // a node IN the graph (or to the page node the Article defines inline). A
    // dangling URI is a guess by another name.
    $declared = array_merge(
        array_column($graph, '@id'),
        [$byType['NewsArticle']['mainEntityOfPage']['@id']],
    );

    $referenced = [
        $byType['NewsArticle']['publisher']['@id'],
        $byType['NewsArticle']['mainEntityOfPage']['breadcrumb']['@id'],
    ];

    foreach ($referenced as $reference) {
        expect($declared)->toContain($reference);
    }
});

it('links the FAQPage to the page the article sits on', function (): void {
    // FAQPage is a subtype of WebPage and it IS this page, so it shares the page's
    // URI — which is the node the Article points at through mainEntityOfPage.
    $content = Content::factory()->published()->create(['title' => ['fa' => 'پرسش و پاسخ']]);

    $content->setTranslation('body', 'fa', [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'چطور؟']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'این‌طور.']]],
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'چرا؟']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'به این دلیل.']]],
        ],
    ]);
    $content->save();

    $graph = app(SchemaBuilder::class)->forArticle($content->fresh(), 'fa');

    $byType = [];

    foreach ($graph as $node) {
        $byType[$node['@type']] = $node;
    }

    expect($byType)->toHaveKey('FAQPage')
        ->and($byType['FAQPage']['@id'])->toBe($byType['NewsArticle']['mainEntityOfPage']['@id'])
        ->and($byType['FAQPage']['inLanguage'])->toBe('fa');
});

it('keeps the built ImageObject on the article instead of reducing it to a URL', function (): void {
    /*
     * The ImageObject was constructed with width, height and the mandatory alt text
     * as its caption, and then discarded in favour of a bare URL string. Google uses
     * the dimensions to decide which rich-result layouts a page qualifies for, so
     * throwing them away costs eligibility that was already paid for.
     */
    $content = Content::factory()->published()->create(['title' => ['fa' => 'خبر تصویری']]);
    $asset = MediaAsset::factory()->withFile()->create(['alt_text' => ['fa' => 'توضیح تصویر']]);

    $content->setFeaturedImage($asset);

    $article = app(SchemaBuilder::class)->article($content->fresh(), 'fa');

    expect($article['image']['@type'])->toBe('ImageObject')
        ->and($article['image']['width'])->toBe($asset->width)
        ->and($article['image']['height'])->toBe($asset->height)
        ->and($article['image']['caption'])->toBe('توضیح تصویر')
        ->and($article['image']['url'])->toBe($asset->getFirstMedia('file')->getFullUrl())
        // An embedded node inherits the document's context; repeating it is noise.
        ->and($article['image'])->not->toHaveKey('@context');
});

it('keeps the full Organization as the publisher when an article stands alone', function (): void {
    // Outside a @graph there is no Organization node for an @id to resolve, so the
    // whole object travels with the Article rather than a name-only stub.
    Setting::put(Setting::SOCIAL_LINKS, ['telegram' => 'https://t.me/example']);

    $content = Content::factory()->published()->create(['title' => ['fa' => 'خبر ناشر']]);

    $article = app(SchemaBuilder::class)->article($content->fresh(), 'fa');

    expect($article['publisher']['@type'])->toBe('Organization')
        ->and($article['publisher']['name'])->toBe('سایت نمونه')
        ->and($article['publisher']['sameAs'])->toBe(['https://t.me/example'])
        ->and($article['publisher'])->toHaveKey('@id')
        ->and($article['publisher'])->not->toHaveKey('@context');
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

/**
 * Read the `<lastmod>` of one entry out of a sitemap index document.
 *
 * Parsed rather than pattern-matched against the whole file, because the assertions
 * below are about one entry differing from another — and a bare str_contains() on the
 * document would pass while every entry still shared the same timestamp, which is the
 * exact defect these tests exist to catch.
 */
function indexLastModFor(string $xml, string $sitemap): ?string
{
    preg_match_all('#<sitemap>(.*?)</sitemap>#s', $xml, $blocks);

    foreach ($blocks[1] as $block) {
        if (! str_contains($block, $sitemap)) {
            continue;
        }

        return preg_match('#<lastmod>(.*?)</lastmod>#', $block, $match) === 1
            ? $match[1]
            : null;
    }

    return null;
}

it('stamps each sitemap in the index with a real lastmod, not the fetch time', function (): void {
    /*
     * Spatie's Sitemap tag defaults lastModificationDate to Carbon::now() in its
     * constructor, so the index was ALREADY emitting a lastmod — the request time,
     * identical on every entry, on every fetch. That is worse than omitting the field:
     * an index claiming all five sitemaps changed this second teaches Google to ignore
     * lastmod for this site, after which the whole archive is re-crawled on the
     * crawler's schedule rather than on ours.
     */
    Content::factory()->published()->create();

    $past = now()->subDays(3)->startOfSecond();

    // Straight to the table: an Eloquent save would re-stamp updated_at to now, which
    // is the value under test.
    DB::table('contents')->update(['updated_at' => $past]);
    DB::table('media_assets')->update(['updated_at' => $past]);

    $xml = get('/sitemap.xml')->assertOk()->content();

    $faLastMod = indexLastModFor($xml, 'sitemap-fa.xml');

    expect($faLastMod)->not->toBeNull()
        // The content's own timestamp, not the moment of the request.
        ->and(Carbon::parse((string) $faLastMod)->toIso8601String())->toBe($past->toIso8601String());
});

it('moves an entry in the index when only that locale changed', function (): void {
    /*
     * The point of lastmod is to distinguish the sitemaps worth re-fetching from the
     * ones that are not. A sign-off on the English translation changes which records
     * sitemap-en.xml CONTAINS (Decision D-5) while touching no content row at all —
     * so if the index derived its dates from content timestamps alone, the one sitemap
     * that genuinely changed would be the one advertised as unchanged.
     */
    $content = Content::factory()->published()->multilingual()->create();

    $past = now()->subDays(5)->startOfSecond();

    DB::table('contents')->update(['updated_at' => $past]);
    DB::table('translation_states')->update(['updated_at' => $past]);

    $content->markTranslationReviewed('en');

    $xml = get('/sitemap.xml')->assertOk()->content();

    $fa = Carbon::parse((string) indexLastModFor($xml, 'sitemap-fa.xml'));
    $en = Carbon::parse((string) indexLastModFor($xml, 'sitemap-en.xml'));

    // Persian is untouched and still reports the old date; English moved.
    expect($fa->toIso8601String())->toBe($past->toIso8601String())
        ->and($en->greaterThan($fa))->toBeTrue();
});

it('dates a translated URL by its review rather than by the last Persian edit', function (): void {
    /*
     * Per-URL lastmod, same reasoning one level down. The record's `updated_at` is only
     * half the answer for /en/...: the translator who made that URL authoritative wrote
     * the TranslationState, not the article, so reporting the article's timestamp tells
     * a crawler the page has not changed since the Persian edit that PRECEDED the
     * review it is waiting for.
     */
    $content = Content::factory()->published()->multilingual()->create();

    $past = now()->subDays(5)->startOfSecond();

    DB::table('contents')->update(['updated_at' => $past]);
    DB::table('translation_states')->update(['updated_at' => $past]);

    $content->markTranslationReviewed('en');

    $enSlug = (string) $content->getTranslation('slug', 'en');
    $faSlug = (string) $content->getTranslation('slug', 'fa');

    $lastModFor = function (string $xml, string $slug): ?string {
        preg_match_all('#<url>(.*?)</url>#s', $xml, $blocks);

        foreach ($blocks[1] as $block) {
            if (str_contains($block, '<loc>') && str_contains($block, $slug)) {
                return preg_match('#<lastmod>(.*?)</lastmod>#', $block, $match) === 1 ? $match[1] : null;
            }
        }

        return null;
    };

    $en = $lastModFor(get('/sitemap-en.xml')->assertOk()->content(), $enSlug);
    $fa = $lastModFor(get('/sitemap-fa.xml')->assertOk()->content(), $faSlug);

    expect($en)->not->toBeNull()
        ->and($fa)->not->toBeNull()
        // The source locale IS the content, so it keeps the content's date...
        ->and(Carbon::parse((string) $fa)->toIso8601String())->toBe($past->toIso8601String())
        // ...while the translated URL reports the sign-off that put it in this sitemap.
        ->and(Carbon::parse((string) $en)->greaterThan(Carbon::parse((string) $fa)))->toBeTrue();
});

it('reads the article table once for all locales when building the media sitemaps', function (): void {
    /*
     * The N-loads-per-locale defect, pinned where it would come back. images() and
     * videos() each called eligibleArticles($locale) INSIDE the locale loop, so each
     * one re-ran a full `Content::query()->live()->with(...)->get()` per locale —
     * three complete loads each, six between them, of identical rows, every row
     * resident. The locale loop is now inside the record walk.
     *
     * Counted as SELECTs against `contents` rather than as a total, so the number
     * means one thing: how many times the archive was read. Three supported locales
     * makes the old behaviour three and the fixed behaviour one, so the assertion
     * cannot pass by accident.
     */
    expect(config('cms.locales.supported'))->toHaveCount(3);

    $content = Content::factory()->published()->create();
    $content->setFeaturedImage(MediaAsset::factory()->withFile()->create());
    $content->attachMediaAsset(
        MediaAsset::factory()->videoWithThumbnail()->create(['alt_text' => ['fa' => 'ویدیو']]),
        MediaRole::Inline,
    );

    $countArticleLoads = function (callable $build): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $build();

        $loads = 0;

        foreach (DB::getQueryLog() as $query) {
            $sql = strtolower((string) $query['query']);

            if (str_starts_with($sql, 'select') && str_contains($sql, 'from "contents"')) {
                $loads++;
            }
        }

        DB::disableQueryLog();

        return $loads;
    };

    $generator = app(SitemapGenerator::class);

    expect($countArticleLoads(fn () => $generator->images()->render()))->toBe(1)
        ->and($countArticleLoads(fn () => $generator->videos()->render()))->toBe(1);
});

it('still associates each locale with the right image URL after the single pass', function (): void {
    // The guard for the test above: reading the table once must not cost a locale its
    // entries. An eligible English translation gets its own URL with the same image.
    $content = Content::factory()->published()->multilingual()->create();
    $content->setFeaturedImage(MediaAsset::factory()->withFile()->create(['alt_text' => ['fa' => 'تصویر']]));
    $content->markTranslationReviewed('en');

    $xml = app(SitemapGenerator::class)->images()->render();

    expect($xml)->toContain((string) $content->getTranslation('slug', 'fa'))
        ->and($xml)->toContain((string) $content->getTranslation('slug', 'en'))
        // Arabic has no reviewed translation, so it contributes nothing (Decision D-5).
        ->and($xml)->not->toContain((string) $content->getTranslation('slug', 'ar'));
});

it('walks each indexable type in batches rather than loading it whole', function (): void {
    /*
     * A chunk size smaller than the number of records, asserted through the OUTPUT
     * rather than through a query count: what must hold is that batching loses no URL.
     * chunkById() keyset-pages on the primary key, so a row leaving the live set
     * mid-generation cannot shift a later page and silently drop an entry — which is
     * what plain chunk()'s OFFSET paging would do.
     */
    config()->set('cms.sitemap.chunk', 2);

    $articles = Content::factory()->count(5)->published()->create();

    $xml = app(SitemapGenerator::class)->forLocale('fa')->render();

    foreach ($articles as $article) {
        expect($xml)->toContain((string) $article->getTranslation('slug', 'fa'));
    }

    expect(substr_count($xml, '<url>'))->toBe(6); // five articles plus the locale root
});
