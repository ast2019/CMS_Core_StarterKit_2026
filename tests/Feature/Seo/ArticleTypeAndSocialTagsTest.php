<?php

declare(strict_types=1);

use App\Enums\ArticleSchemaType;
use App\Filament\Resources\Contents\Pages\EditContent;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\Setting;
use App\Models\User;
use App\Services\Seo\SchemaBuilder;
use App\Services\Seo\SocialTagBuilder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * The article's structured-data type (Requirement 7.3) and its social cards
 * (Requirement 7.1).
 *
 * Both were previously fixed values a site could not change: SchemaBuilder::article()
 * hardcoded NewsArticle, and the OG tags were a private controller method with no
 * Twitter equivalent and no per-record override.
 */
beforeEach(function (): void {
    Setting::put(Setting::SITE_NAME, ['fa' => 'سایت نمونه', 'en' => 'Sample Site'], isTranslatable: true);
});

// ---------------------------------------------------------------------------
// Article schema type
// ---------------------------------------------------------------------------

it('publishes an article as NewsArticle by default, exactly as before', function (): void {
    /*
     * The compatibility assertion, and the reason the column defaults to NewsArticle
     * rather than to the more neutral Article: every existing record was already being
     * published as a NewsArticle, so any other default would silently restate the
     * meaning of the whole archive the moment this shipped.
     */
    $content = Content::factory()->published()->create();

    expect($content->schemaType())->toBe(ArticleSchemaType::NewsArticle)
        ->and(app(SchemaBuilder::class)->article($content, 'fa')['@type'])->toBe('NewsArticle');
});

it('honours the editor-selected type', function (ArticleSchemaType $type): void {
    $content = Content::factory()->published()->create(['schema_type' => $type]);

    expect(app(SchemaBuilder::class)->article($content, 'fa')['@type'])->toBe($type->value);
})->with([
    'article' => [ArticleSchemaType::Article],
    'news' => [ArticleSchemaType::NewsArticle],
    'blog post' => [ArticleSchemaType::BlogPosting],
]);

it('keeps every other article property when the type changes', function (): void {
    // The type is the only thing that varies: a different factory method must not
    // quietly drop the publisher, the headline or the @id cross-references.
    $content = Content::factory()->published()->create([
        'title' => ['fa' => 'راهنمای خرید لپتاپ'],
        'schema_type' => ArticleSchemaType::Article,
    ]);

    $article = app(SchemaBuilder::class)->article($content, 'fa');

    expect($article['@type'])->toBe('Article')
        ->and($article['headline'])->toBe('راهنمای خرید لپتاپ')
        ->and($article)->toHaveKeys(['@id', 'mainEntityOfPage', 'publisher', 'inLanguage']);
});

it('carries the chosen type through the Delivery SEO graph', function (): void {
    $content = Content::factory()->published()->create([
        'slug' => ['fa' => 'راهنما'],
        'schema_type' => ArticleSchemaType::BlogPosting,
    ]);

    $response = getJson('/api/v1/news/راهنما/seo?locale=fa')->assertOk();

    $types = collect($response->json('data.json_ld.@graph'))->pluck('@type');

    expect($types)->toContain('BlogPosting')
        ->and($types)->not->toContain('NewsArticle');
});

it('offers the type on the article form and saves it', function (): void {
    $admin = User::factory()->admin()->withMfaEnrolled()->create();
    actingAs($admin);

    $content = Content::factory()->published()->create();
    $content->setFeaturedImage(MediaAsset::factory()->create());

    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->assertFormFieldExists('schema_type')
        // The per-option explanation is the feature: "NewsArticle" versus "Article"
        // means nothing to an editor without the sentence saying one claims recency.
        ->assertSee(__('cms.schema_type.NewsArticle_help'))
        ->fillForm(['schema_type' => ArticleSchemaType::Article->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($content->fresh()?->schemaType())->toBe(ArticleSchemaType::Article);
});

it('is not translatable, because an article cannot be two types at once', function (): void {
    // A per-locale type would let the same guide be announced as a NewsArticle in
    // Persian and a BlogPosting in English — a contradiction anything resolving the
    // hreflang cluster can see.
    expect((new Content)->getTranslatableAttributes())->not->toContain('schema_type');
});

// ---------------------------------------------------------------------------
// Open Graph overrides
// ---------------------------------------------------------------------------

it('derives the OG values from the meta fields when no override is set', function (): void {
    // Byte-for-byte what the endpoint served before the override columns existed.
    $content = Content::factory()->published()->create([
        'meta_title' => ['fa' => 'عنوان متا'],
        'meta_description' => ['fa' => 'توضیح متا'],
    ]);

    $og = app(SocialTagBuilder::class)->openGraph($content, 'fa');

    expect($og['og:title'])->toBe('عنوان متا')
        ->and($og['og:description'])->toBe('توضیح متا')
        ->and($og['og:type'])->toBe('article');
});

it('prefers a per-record OG override over the derived value', function (): void {
    /*
     * The two strings have different jobs: a meta title competes in a results page
     * against nine others, a social card is read by someone scrolling a feed. Before
     * the override the only way to have both was to compromise on one.
     */
    $content = Content::factory()->published()->create([
        'meta_title' => ['fa' => 'عنوان متا'],
        'meta_description' => ['fa' => 'توضیح متا'],
        'og_title' => ['fa' => 'عنوانی برای شبکههای اجتماعی'],
        'og_description' => ['fa' => 'توضیحی گفتاریتر برای کارت اشتراکگذاری'],
    ]);

    $og = app(SocialTagBuilder::class)->openGraph($content, 'fa');

    expect($og['og:title'])->toBe('عنوانی برای شبکههای اجتماعی')
        ->and($og['og:description'])->toBe('توضیحی گفتاریتر برای کارت اشتراکگذاری')
        // The meta fields themselves are untouched: the override is a second string,
        // not a replacement.
        ->and($content->metaTitleFor('fa'))->toBe('عنوان متا');
});

it('falls back per locale, so an override in one locale does not leak into another', function (): void {
    $content = Content::factory()->published()->create([
        'title' => ['fa' => 'عنوان فارسی', 'en' => 'English title'],
        'slug' => ['fa' => 'خبر', 'en' => 'news-item'],
        'og_title' => ['fa' => 'کارت فارسی'],
    ]);

    expect(app(SocialTagBuilder::class)->openGraph($content, 'fa')['og:title'])->toBe('کارت فارسی')
        ->and(app(SocialTagBuilder::class)->openGraph($content, 'en')['og:title'])->toBe('English title');
});

// ---------------------------------------------------------------------------
// Twitter cards
// ---------------------------------------------------------------------------

it('claims a large card only when the image is known to be big enough', function (): void {
    $content = Content::factory()->published()->create();
    $content->setFeaturedImage(MediaAsset::factory()->withFile()->create([
        'width' => 1920,
        'height' => 1080,
    ]));

    expect(app(SocialTagBuilder::class)->twitter($content->fresh(), 'fa')['twitter:card'])
        ->toBe(SocialTagBuilder::CARD_SUMMARY_LARGE_IMAGE);
});

it('falls back to a small card when the image is too small', function (): void {
    $content = Content::factory()->published()->create();
    $content->setFeaturedImage(MediaAsset::factory()->withFile()->create([
        'width' => 120,
        'height' => 80,
    ]));

    // Claiming a large card for an image X will not render large produces a visibly
    // broken share card; `summary` on a big image merely under-sells it.
    expect(app(SocialTagBuilder::class)->twitter($content->fresh(), 'fa')['twitter:card'])
        ->toBe(SocialTagBuilder::CARD_SUMMARY);
});

it('falls back to a small card when the dimensions are unknown', function (): void {
    /*
     * True of anything uploaded before dimension capture, and of anything a backfill
     * has not reached. "Omit rather than guess" applied to a card type: such records
     * start reporting the large card on their own once their dimensions are filled in,
     * with no change here.
     */
    $content = Content::factory()->published()->create();
    $content->setFeaturedImage(MediaAsset::factory()->withFile()->create([
        'width' => null,
        'height' => null,
    ]));

    expect(app(SocialTagBuilder::class)->twitter($content->fresh(), 'fa')['twitter:card'])
        ->toBe(SocialTagBuilder::CARD_SUMMARY);
});

it('emits a small card and no image when there is no image at all', function (): void {
    $content = Content::factory()->published()->create();

    $twitter = app(SocialTagBuilder::class)->twitter($content, 'fa');

    expect($twitter['twitter:card'])->toBe(SocialTagBuilder::CARD_SUMMARY)
        ->and($twitter['twitter:image'])->toBeNull()
        ->and($twitter['twitter:image:alt'])->toBeNull();
});

it('carries the alt text into the card, which is its only accessible description', function (): void {
    $content = Content::factory()->published()->create();
    $content->setFeaturedImage(MediaAsset::factory()->withFile()->create([
        'alt_text' => ['fa' => 'نمایی از ساختمان مرکزی'],
    ]));

    $twitter = app(SocialTagBuilder::class)->twitter($content->fresh(), 'fa');

    // Requirement 2.7 makes alt text mandatory, which is what makes this free to emit.
    expect($twitter['twitter:image:alt'])->toBe('نمایی از ساختمان مرکزی')
        ->and($twitter['twitter:image'])->toStartWith('http');
});

it('repeats the OG text in the Twitter tags rather than inventing a third string', function (): void {
    /*
     * X falls back to og:* when twitter:* is absent, so DIFFERENT text here would be a
     * second thing to keep in step with no editorial control offering it.
     */
    $content = Content::factory()->published()->create([
        'og_title' => ['fa' => 'عنوان کارت'],
    ]);

    $og = app(SocialTagBuilder::class)->openGraph($content, 'fa');
    $twitter = app(SocialTagBuilder::class)->twitter($content, 'fa');

    expect($twitter['twitter:title'])->toBe($og['og:title'])
        ->and($twitter['twitter:description'])->toBe($og['og:description']);
});

// ---------------------------------------------------------------------------
// The Delivery payload — a documented API change (RULE #3)
// ---------------------------------------------------------------------------

it('serves the twitter card and the keyphrase analysis in the SEO payload', function (): void {
    $content = Content::factory()->published()->create([
        'slug' => ['fa' => 'خبر-ویژه'],
        'focus_keyphrase' => ['fa' => 'خبر ویژه'],
        'meta_title' => ['fa' => 'خبر ویژه امروز'],
    ]);
    $content->setFeaturedImage(MediaAsset::factory()->withFile()->create());

    $response = getJson('/api/v1/news/خبر-ویژه/seo?locale=fa')->assertOk();

    $response
        ->assertJsonStructure([
            'data' => [
                'canonical',
                'meta' => ['title', 'description', 'robots'],
                'open_graph',
                // New keys. A frontend that ignores them renders exactly as before.
                'twitter' => ['twitter:card', 'twitter:title', 'twitter:description'],
                'seo_warnings',
                'seo_analysis' => ['keyphrase', 'score', 'band', 'checks'],
            ],
        ])
        ->assertJsonPath('data.twitter.twitter:card', SocialTagBuilder::CARD_SUMMARY_LARGE_IMAGE)
        ->assertJsonPath('data.seo_analysis.keyphrase', 'خبر ویژه');

    /*
     * The panel and the API report the SAME analysis. That is the point of putting it
     * on the model: two implementations would eventually disagree, and an editor told
     * one score while a build check reads another has no way to tell which is wrong.
     */
    expect($response->json('data.seo_analysis'))->toBe($content->seoAnalysisFor('fa'));
});
