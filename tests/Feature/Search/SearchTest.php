<?php

declare(strict_types=1);

use App\Concerns\IsSearchable;
use App\Jobs\SyncSearchIndexes;
use App\Models\Category;
use App\Models\Content;
use App\Models\Tag;
use App\Providers\CmsServiceProvider;
use App\Support\TipTap;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\ModelObserver;

use function Pest\Laravel\getJson;

/**
 * Requirements 6.1-6.5. Decisions D-5, D-7.
 */
it('uses a separate index per locale', function (): void {
    // Requirement 6.2. One shared index would make Persian, English and Arabic
    // compete under a single stemmer and relevance model.
    $content = Content::factory()->create();

    expect($content->forSearchLocale('fa')->searchableAs())->toBe('contents_fa')
        ->and($content->forSearchLocale('en')->searchableAs())->toBe('contents_en')
        ->and($content->forSearchLocale('ar')->searchableAs())->toBe('contents_ar');
});

it('indexes body as plain text rather than TipTap JSON', function (): void {
    /*
     * Decision D-7. Raw TipTap JSON would index structural keys, so every document
     * would match a search for "paragraph" and rank by nesting depth.
     */
    $content = Content::factory()->create();

    $document = $content->forSearchLocale('fa')->toSearchableArray();

    expect($document['body'])->toBeString()
        ->and($document['body'])->not->toContain('"type"')
        ->and($document['body'])->not->toContain('paragraph')
        ->and($document['body'])->not->toBe('');
});

it('includes prose from custom blocks in the indexed body', function (): void {
    // A callout's text lives in attrs, not in child text nodes, so a naive walk would
    // index the article while silently omitting its callouts.
    $content = Content::factory()->create([
        'body' => ['fa' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'متن اصلی']]],
                ['type' => 'callout', 'attrs' => ['tone' => 'info', 'body' => 'نکتهٔ مهم درون بلوک']],
            ],
        ]],
    ]);

    $document = $content->forSearchLocale('fa')->toSearchableArray();

    expect($document['body'])->toContain('متن اصلی')
        ->and($document['body'])->toContain('نکتهٔ مهم درون بلوک');
});

it('indexes tag and category names alongside the text', function (): void {
    // Requirement 6.1 — so a search for a category name finds its articles even when
    // the word never appears in the body.
    $category = Category::factory()->create(['name' => ['fa' => 'اقتصاد']]);
    $tag = Tag::factory()->create(['name' => ['fa' => 'تحلیل']]);

    $content = Content::factory()->create();
    $content->categories()->attach($category);
    $content->tags()->attach($tag);
    $content->load(['categories', 'tags']);

    $document = $content->forSearchLocale('fa')->toSearchableArray();

    expect($document['categories'])->toContain('اقتصاد')
        ->and($document['tags'])->toContain('تحلیل');
});

it('keeps drafts out of the index', function (): void {
    /*
     * A draft in the search index is a content leak: the title and excerpt of
     * unpublished work would be readable by anyone who guessed a query.
     */
    $draft = Content::factory()->create();
    $live = Content::factory()->published()->create();

    expect($draft->forSearchLocale('fa')->shouldBeSearchable())->toBeFalse()
        ->and($live->forSearchLocale('fa')->shouldBeSearchable())->toBeTrue();
});

it('keeps a scheduled article out of the index until it is due', function (): void {
    // Requirement 3.6 — published status plus a future date is not live.
    $scheduled = Content::factory()->scheduled()->create();

    expect($scheduled->forSearchLocale('fa')->shouldBeSearchable())->toBeFalse();
});

it('does not index a locale whose translation is unreviewed', function (): void {
    /*
     * Decision D-5 applied to search. Indexing fallback Persian under the English
     * index means an English query returns Persian results, which reads as a broken
     * site rather than a missing translation.
     */
    $content = Content::factory()->published()->multilingual()->create();

    expect($content->forSearchLocale('en')->shouldBeSearchable())->toBeFalse();

    $content->markTranslationReviewed('en');

    expect($content->fresh()->forSearchLocale('en')->shouldBeSearchable())->toBeTrue();
});

it('queues an index sync when content is saved', function (): void {
    // Requirement 6.4 — asynchronous, so a save never waits on the search engine and
    // an engine outage cannot make the panel unusable.
    Queue::fake();

    $content = Content::factory()->create();

    Queue::assertPushed(SyncSearchIndexes::class);

    $content->setTranslation('title', 'fa', 'عنوان تازه');
    $content->save();

    Queue::assertPushed(SyncSearchIndexes::class, fn (): bool => true);
});

it('queues a removal when content is deleted', function (): void {
    $content = Content::factory()->published()->create();

    Queue::fake();

    $content->delete();

    // Unpublishing or deleting must take the record OUT, or search keeps serving a
    // result that 404s.
    Queue::assertPushed(SyncSearchIndexes::class);
});

it('disables Scout own observer so only the per-locale sync writes', function (): void {
    /*
     * Scout's observer writes ONE index — whatever searchableAs() resolves to at that
     * instant — so with locale-dependent names it would index only the current
     * request's locale and leave the others stale.
     */
    expect(ModelObserver::syncingDisabledFor(Content::class))->toBeTrue();
});

it('applies the searchable trait to the searchable model list', function (): void {
    foreach (CmsServiceProvider::SEARCHABLE_MODELS as $model) {
        expect(in_array(IsSearchable::class, class_uses_recursive($model), true))->toBeTrue(
            "{$model} is listed as searchable but does not use the IsSearchable trait."
        );
    }
});

it('rejects a search term that is too short', function (): void {
    // A one-character query matches most of the corpus and costs a near-full scan.
    getJson('/api/v1/search?q=a')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['q']);
});

it('returns paginated results scoped to the locale', function (): void {
    Content::factory()->published()->count(3)->create([
        'title' => ['fa' => 'گزارش اقتصادی ویژه'],
    ]);

    $response = getJson('/api/v1/search?q=اقتصادی&per_page=2')->assertOk();

    expect($response->json('meta.locale'))->toBe('fa')
        ->and($response->json('meta.query'))->toBe('اقتصادی')
        ->and($response->json('meta.per_page'))->toBe(2)
        ->and($response->json('data'))->toBeArray();
});

it('bounds the search page size', function (): void {
    $response = getJson('/api/v1/search?q=تست&per_page=100000')->assertOk();

    expect($response->json('meta.per_page'))->toBe(50);
});

it('returns a structured 503 when the search backend is unreachable', function (): void {
    /*
     * Requirement 6.5. Search is a degraded-mode feature: the rest of the site works
     * without it, so an outage must not surface as a 500 that looks like the whole API
     * is down.
     */
    config()->set('scout.driver', 'meilisearch');
    config()->set('scout.meilisearch.host', 'http://127.0.0.1:1');

    getJson('/api/v1/search?q=تست')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60')
        ->assertJsonPath('message', 'Search is temporarily unavailable.');
});

it('serves nothing when the search module is disabled', function (): void {
    config()->set('cms.modules.search', false);

    getJson('/api/v1/search?q=تست')->assertNotFound();
});

it('extracts question headings for the GEO strategy', function (): void {
    // Blueprint §6 asks for question-based headings; TipTap::headings is what makes
    // them available to both the FAQPage schema and the API payload.
    $document = [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'چگونه کار می‌کند؟']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'توضیح']]],
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'چرا مهم است؟']]],
        ],
    ];

    expect(TipTap::headings($document))->toBe(['چگونه کار می‌کند؟', 'چرا مهم است؟']);
});
