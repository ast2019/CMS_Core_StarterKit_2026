<?php

declare(strict_types=1);

use App\Contracts\HasSeoMetadata;
use App\Enums\ContentStatus;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Contents\Pages\EditContent;
use App\Filament\Resources\Galleries\Pages\EditGallery;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * The SEO guidance HasSeoMeta always computed, now visible in the panel.
 *
 * Requirements 7.1, 7.3.
 *
 * Until this change the only consumer of seoWarningsFor() was the Delivery
 * SeoController — i.e. the editor could learn about a missing meta title from a
 * crawl report, which is precisely what the trait's own docblock says it exists to
 * prevent.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->withMfaEnrolled()->create();
});

it('renders the SEO block on every resource that has SEO metadata', function (string $page, string $model): void {
    actingAs($this->admin);

    $record = $model::factory()->create();

    Livewire::test($page, ['record' => $record->getRouteKey()])
        ->assertOk()
        // The advisory warnings, which no form used to show at all.
        ->assertSee(__('cms.seo.warnings'))
        /*
         * A field per locale, so a title that is fine in Persian and missing in
         * English is two separate signals rather than one averaged one.
         */
        ->assertFormFieldExists('meta_title.fa')
        ->assertFormFieldExists('meta_title.en')
        ->assertFormFieldExists('meta_title.ar')
        ->assertFormFieldExists('meta_description.fa')
        // Previously absent from Page, Gallery and Category alike.
        ->assertFormFieldExists('robots_meta.fa');
})->with([
    'content' => [EditContent::class, Content::class],
    'page' => [EditPage::class, Page::class],
    'gallery' => [EditGallery::class, Gallery::class],
    'category' => [EditCategory::class, Category::class],
]);

it('warns about an over-long meta title and a missing description', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->create([
        'meta_title' => ['fa' => str_repeat('ط', HasSeoMetadata::META_TITLE_ADVISORY_LIMIT + 5)],
        'meta_description' => ['fa' => ''],
        'excerpt' => ['fa' => ''],
    ]);

    /*
     * Asserted against the model's own rules rather than against rendered HTML:
     * the form reads seoWarningsFor() on a throwaway instance filled from live form
     * state, so agreement between the two IS the behaviour under test.
     */
    expect($content->seoWarningsFor('fa'))
        ->toContain('title_too_long')
        ->toContain('missing_description');

    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->assertOk()
        ->assertSee(__('cms.seo.warning.title_too_long', [
            'title_limit' => HasSeoMetadata::META_TITLE_ADVISORY_LIMIT,
        ]))
        ->assertSee(__('cms.seo.warning.missing_description'));
});

it('reports no warnings for a record with sound metadata', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->create([
        'meta_title' => ['fa' => 'عنوانی کوتاه و مناسب'],
        'meta_description' => ['fa' => 'توضیحی کوتاه، روشن و در محدودهٔ توصیهشده برای نمایش در نتایج جستوجو.'],
    ]);

    expect($content->seoWarningsFor('fa'))->toBe([]);

    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->assertOk()
        ->assertSee(__('cms.seo.no_warnings'));
});

it('counts meta title characters against the advisory limit, not the column length', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->create();

    // Persian, so a byte-length counter would report roughly double and warn about
    // a limit the editor has not reached. mb_strlen is the behaviour under test.
    $title = str_repeat('ب', 30);

    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->fillForm(['meta_title.fa' => $title])
        ->assertSee(__('cms.seo.character_count', [
            'count' => 30,
            'limit' => HasSeoMetadata::META_TITLE_ADVISORY_LIMIT,
        ]));
});

it('lets a static page be set noindex from the panel', function (): void {
    actingAs($this->admin);

    $page = Page::factory()->create(['status' => ContentStatus::Published->value]);
    // RULE #7 — the form refuses to save without one, so the fixture needs it.
    $page->setFeaturedImage(MediaAsset::factory()->create());

    /*
     * PageForm had no robots_meta field at all, so a thank-you or landing page
     * could not be kept out of the index without editing the database — even
     * though the column and robotsMetaFor() were both there all along.
     */
    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertOk()
        ->assertFormFieldExists('robots_meta.fa')
        ->fillForm(['robots_meta.fa' => 'noindex, follow'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->fresh()?->robotsMetaFor('fa'))->toBe('noindex, follow');
});

it('persists gallery SEO metadata the form never used to offer', function (): void {
    actingAs($this->admin);

    $gallery = Gallery::factory()->create(['status' => ContentStatus::Published->value]);
    $gallery->setFeaturedImage(MediaAsset::factory()->create());

    Livewire::test(EditGallery::class, ['record' => $gallery->getRouteKey()])
        ->assertOk()
        ->fillForm([
            'meta_title.fa' => 'عنوان متای گالری',
            'meta_description.fa' => 'توضیح متای گالری',
            'robots_meta.fa' => 'noindex, nofollow',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $gallery->fresh();

    expect($fresh?->metaTitleFor('fa'))->toBe('عنوان متای گالری')
        ->and($fresh?->metaDescriptionFor('fa'))->toBe('توضیح متای گالری')
        ->and($fresh?->robotsMetaFor('fa'))->toBe('noindex, nofollow');
});

it('persists category SEO metadata including the robots directive', function (): void {
    actingAs($this->admin);

    $category = Category::factory()->create();

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->assertOk()
        ->fillForm([
            'meta_title.fa' => 'عنوان متای دسته',
            'robots_meta.fa' => 'noindex, follow',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($category->fresh()?->robotsMetaFor('fa'))->toBe('noindex, follow');
});

/**
 * The crash the panel work uncovered.
 *
 * metaDescriptionFor() reached for `excerpt` unconditionally and metaTitleFor()
 * reached for `title` the same way. Only Content has an excerpt and only three of
 * the four models have a title, so spatie threw AttributeIsNotTranslatable and took
 * the response with it.
 */
it('resolves SEO metadata for a model with no excerpt', function (): void {
    $page = Page::factory()->create([
        'title' => ['fa' => 'برگهٔ آزمایشی'],
        'meta_description' => ['fa' => ''],
    ]);

    expect($page->metaDescriptionFor('fa'))->toBe('')
        ->and($page->metaTitleFor('fa'))->toBe('برگهٔ آزمایشی')
        ->and($page->seoWarningsFor('fa'))->toContain('missing_description');
});

it('resolves SEO metadata for a model with no title attribute', function (): void {
    // A Category calls its display attribute `name`, which is why a single
    // hardcoded 'title' fallback was never safe.
    $category = Category::factory()->create([
        'name' => ['fa' => 'دستهٔ آزمایشی'],
        'meta_title' => ['fa' => ''],
    ]);

    expect($category->metaTitleFor('fa'))->toBe('دستهٔ آزمایشی')
        ->and($category->metaDescriptionFor('fa'))->toBeString();
});

it('falls back to the description for a gallery, which has no excerpt', function (): void {
    $gallery = Gallery::factory()->create([
        'description' => ['fa' => 'گالری تصاویر افتتاحیه'],
        'meta_description' => ['fa' => ''],
    ]);

    // An empty meta description is worse than a generated one, which is the
    // trait's stated position — it just had no way to act on it for a Gallery.
    expect($gallery->metaDescriptionFor('fa'))->toBe('گالری تصاویر افتتاحیه')
        ->and($gallery->seoWarningsFor('fa'))->not->toContain('missing_description');
});

it('serves the branded 404 page instead of a 500', function (): void {
    /*
     * The user-visible consequence of the unguarded excerpt fallback: this endpoint
     * called metaDescriptionFor() on a Page, so any 404 page without a hand-written
     * meta description returned a 500 — and the frontend had no branded 404 to
     * render. It was untested, so nobody noticed.
     */
    Page::factory()->create([
        'system_key' => Page::SYSTEM_NOT_FOUND,
        'status' => ContentStatus::Published->value,
        'publish_date' => now()->subDay(),
        'title' => ['fa' => 'صفحه پیدا نشد'],
        'meta_description' => ['fa' => ''],
    ]);

    getJson('/api/v1/not-found-page')
        ->assertOk()
        ->assertJsonPath('data.seo.meta_description', '');
});
