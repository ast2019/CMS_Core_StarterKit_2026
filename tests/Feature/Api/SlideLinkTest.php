<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Enums\TranslationStatus;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Slide;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\getJson;

/**
 * A slide's destination, which now works exactly like a menu item's.
 *
 * Requirements 1.1, 3.4, 5.2, 5.5, 7.6, 8.3.
 *
 * `slides.link` was a bare string. That is the same defect MenuItem already solved: a
 * hardcoded URL sends every locale to the Persian page, and renaming the target's slug
 * breaks the slideshow with nothing in the panel to show why. The shared rules live in
 * App\Concerns\HasLinkTarget, so these tests are as much about the two models agreeing
 * as about slides.
 */
it('resolves a linked record per locale instead of sending everyone to one path', function (): void {
    $content = Content::factory()->published()->create([
        'title' => ['fa' => 'خبر ویژه', 'en' => 'Featured story'],
    ]);

    $slide = Slide::factory()->pointingAt($content)->create();

    $fa = $content->getTranslation('slug', 'fa', useFallbackLocale: false);
    $en = $content->getTranslation('slug', 'en', useFallbackLocale: false);

    expect($en)->not->toBe($fa)
        ->and($slide->resolveUrl('fa'))->toBe("/fa/news/{$fa}")
        ->and($slide->resolveUrl('en'))->toBe("/en/news/{$en}");
});

it('follows the target when its slug is renamed', function (): void {
    // The whole reason to prefer a relation over a typed URL: the slide moves with the
    // article rather than pointing at a path that no longer exists.
    $content = Content::factory()->published()->create();
    $slide = Slide::factory()->pointingAt($content)->create();

    $content->setTranslation('slug', 'fa', 'slug-after-rename');
    $content->save();

    expect($slide->fresh()?->resolveUrl('fa'))->toBe('/fa/news/slug-after-rename');
});

it('keeps a raw external link working', function (): void {
    // An external campaign microsite or a PDF has no CMS record to point at, so the
    // string column had to stay rather than be replaced.
    $slide = Slide::factory()->create(['link' => 'https://example.test/campaign']);

    expect($slide->resolveUrl('fa'))->toBe('https://example.test/campaign')
        ->and($slide->resolveUrl('ar'))->toBe('https://example.test/campaign');
});

it('allows a slide with no destination at all', function (): void {
    /*
     * The one place Slide and MenuItem diverge (Slide::linkTargetIsRequired()). A
     * decorative hero with no call to action is a normal thing to publish, and requiring
     * a destination would reject every slide created before the morph existed on its next
     * save.
     */
    $slide = Slide::factory()->withoutLink()->create();

    expect($slide->exists)->toBeTrue()
        ->and($slide->resolveUrl('fa'))->toBeNull();

    // ...whereas the same row shape is refused for a menu item.
    expect(fn () => MenuItem::factory()->create(['link' => null]))
        ->toThrow(ValidationException::class);
});

it('prefers the relation over a stale raw link and clears the loser on save', function (): void {
    // Filament does not dehydrate a hidden field, so the old URL survives the save that
    // re-points a slide at a record. Checking `link` first is what made that change look
    // like it had done nothing.
    $page = Page::factory()->create();

    $slide = Slide::factory()->create(['link' => '/fa/hardcoded']);
    $slide->linkable_type = $page::class;
    $slide->linkable_id = $page->getKey();
    $slide->save();

    expect($slide->fresh()?->link)->toBeNull()
        ->and($slide->resolveUrl('fa'))->toBe('/fa/'.$page->getTranslation('slug', 'fa'));
});

it('drops a half-written morph rather than storing a type pointing at nothing', function (): void {
    $slide = Slide::factory()->create(['link' => '/fa/keep-me']);

    $slide->linkable_type = Page::class;
    $slide->linkable_id = null;
    $slide->save();

    expect($slide->fresh()?->linkable_type)->toBeNull()
        ->and($slide->fresh()?->link)->toBe('/fa/keep-me');
});

it('degrades to no link when the target is unpublished, deleted or in a disabled module', function (): void {
    $draft = Content::factory()->create(['status' => ContentStatus::Draft]);
    $gallery = Gallery::factory()->published()->create();
    $doomed = Page::factory()->create();

    $draftSlide = Slide::factory()->pointingAt($draft)->create();
    $gallerySlide = Slide::factory()->pointingAt($gallery)->create();
    $danglingSlide = Slide::factory()->pointingAt($doomed)->create();

    $doomed->forceDelete();

    expect($draftSlide->resolveUrl('fa'))->toBeNull()
        ->and($danglingSlide->fresh()?->resolveUrl('fa'))->toBeNull()
        ->and($gallerySlide->resolveUrl('fa'))->not->toBeNull();

    // Requirement 1.1 — with the module off the Delivery API answers 404 for every
    // gallery, so a gallery slide would be a hero linking into nothing.
    config()->set('cms.modules.gallery', false);

    expect($gallerySlide->resolveUrl('fa'))->toBeNull();
});

it('serves the resolved link and its target in the delivery payload', function (): void {
    $page = Page::factory()->create(['title' => ['fa' => 'درباره ما']]);

    Slide::factory()->pointingAt($page)->create(['position' => 0]);

    $response = getJson('/api/v1/slides')->assertOk();

    expect($response->json('data.0.link'))->toBe('/fa/'.$page->getTranslation('slug', 'fa'))
        // So the frontend can choose an internal transition over a full navigation
        // without pattern-matching the path.
        ->and($response->json('data.0.link_target.type'))->toBe('page')
        ->and($response->json('data.0.link_target.id'))->toBe($page->id);
});

it('reports a link resolved through the source locale rather than hiding it', function (): void {
    /*
     * Requirement 5.5 — no SILENT fallback. The slide keeps its link (a slideshow that
     * empties itself in every locale but Persian is a broken homepage, and the Delivery
     * API resolves a source-locale slug under any locale), but it says so — because the
     * sitemap and canonical layers refuse to advertise that same URL (Decision D-5).
     */
    $page = Page::factory()->create(['title' => ['fa' => 'درباره ما']]);

    Slide::factory()->pointingAt($page)->create();

    $response = getJson('/api/v1/slides?locale=en')->assertOk();

    expect($response->json('data.0.link'))->toBe('/en/'.$page->getTranslation('slug', 'fa'))
        ->and($response->json('data.0.meta.is_fallback'))->toBeTrue()
        ->and($response->json('data.0.meta.fallback_locale'))->toBe('fa')
        ->and($response->json('data.0.meta.link_translation_status'))
        ->toBe(TranslationStatus::NotTranslated->value);
});

it('omits the link target for a raw-URL slide', function (): void {
    Slide::factory()->create(['link' => '/fa/news']);

    $response = getJson('/api/v1/slides')->assertOk();

    expect($response->json('data.0.link'))->toBe('/fa/news')
        ->and($response->json('data.0.link_target'))->toBeNull();
});

it('serves no slides when the slide module is switched off', function (): void {
    // Requirement 1.1 — stage 1 flagged this endpoint as ungated.
    Slide::factory()->create();

    getJson('/api/v1/slides')->assertOk();

    config()->set('cms.modules.slide', false);

    getJson('/api/v1/slides')->assertNotFound();
});

it('costs no extra query per slide to resolve its target', function (): void {
    /*
     * Asserted as a COMPARISON rather than an absolute ceiling, and deliberately so.
     * The payload already spends one query per slide on `should_preload`
     * (Slide::isFirstActive() re-queries for the first active slide), which predates the
     * morph and is not what this test is about — an absolute bound would fold the two
     * together and the number would mean nothing.
     *
     * What matters here is that pointing five slides at five records costs the same as
     * five raw-URL slides. Without the `linkable` eager load in SiteController it would
     * cost five more, on a public cached endpoint fetched for the homepage of every
     * visit.
     */
    $count = Slide::maxSlides();

    $measure = function () use ($count): int {
        expect(Slide::query()->where('is_active', true)->count())->toBe($count);

        // flushQueryLog(), because disableQueryLog() stops recording without clearing
        // what was already recorded — so the second measurement would silently include
        // the first one's queries and every comparison here would be meaningless.
        DB::flushQueryLog();
        DB::enableQueryLog();

        getJson('/api/v1/slides')->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(0, $count - 1) as $position) {
        Slide::factory()->create(['position' => $position, 'link' => "/fa/raw-{$position}"]);
    }

    $rawLinkQueries = $measure();

    Slide::query()->delete();

    foreach (Page::factory()->count($count)->create() as $position => $page) {
        Slide::factory()->pointingAt($page)->create(['position' => $position]);
    }

    /*
     * Exactly two more, whatever the slide count: one to load the morph targets and one
     * to load their translation states. Both are flat eager loads, which is the property
     * worth pinning — a regression here would show up as +2 per slide, not +2 in total.
     */
    expect($measure())->toBeLessThanOrEqual($rawLinkQueries + 2);
});
