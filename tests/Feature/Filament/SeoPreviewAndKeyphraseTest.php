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

/**
 * The search-result preview and the keyphrase score, in the panel.
 *
 * Requirement 7.1.
 *
 * The numbers themselves are asserted in tests/Feature/Seo/SeoAnalysisTest.php,
 * against the service. What is asserted HERE is the part only the panel can get
 * wrong: that the block is present on every SEO-bearing resource, that it reflects
 * UNSAVED state, that the keyphrase persists, and that the optional field appears
 * exactly on the models that carry the column.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->withMfaEnrolled()->create();
});

it('renders the search-result preview on every SEO-bearing resource', function (string $page, string $model): void {
    actingAs($this->admin);

    $record = $model::factory()->create();

    Livewire::test($page, ['record' => $record->getRouteKey()])
        ->assertOk()
        ->assertSee(__('cms.seo.preview.label'))
        // The preview block itself, not just its label — a Placeholder whose content
        // closure threw would still render the label.
        ->assertSee('cms-serp', escape: false);
})->with([
    'content' => [EditContent::class, Content::class],
    'page' => [EditPage::class, Page::class],
    'gallery' => [EditGallery::class, Gallery::class],
    'category' => [EditCategory::class, Category::class],
]);

it('previews a title typed but not yet saved', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->published()->create(['meta_title' => ['fa' => 'عنوان قدیمی']]);

    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->fillForm(['meta_title.fa' => 'عنوان تازه و ذخیرهنشده'])
        ->assertSee('عنوان تازه و ذخیرهنشده')
        // The saved value is gone from the preview, which is the whole point of
        // filling a throwaway instance from form state rather than reading the record.
        ->assertDontSee('عنوان قدیمی');
});

it('shows the words a search result would cut', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->published()->create();

    $tail = 'دنبالهٔ حذفشدنی';
    $title = str_repeat('واژه ', 15).$tail;

    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->fillForm(['meta_title.fa' => $title])
        // Struck through in the markup rather than dropped: the editor has to be able
        // to see WHICH words fall off the end, or the limit is just a number they
        // already had from the counter.
        ->assertSee('cms-serp-cut', escape: false)
        ->assertSee($tail);
});

it('previews the homepage at the locale root even before the form is saved', function (): void {
    actingAs($this->admin);

    /*
     * The reason the preview clones the record instead of starting from a blank
     * instance: `system_key` is not translatable and is not in the SEO section, so a
     * fresh instance would answer "not the homepage" and preview /fa/{slug} — a URL
     * stage 2 deliberately stopped serving.
     */
    $home = Page::factory()->homePage()->create(['slug' => ['fa' => 'home-page']]);
    $home->setFeaturedImage(MediaAsset::factory()->create());

    Livewire::test(EditPage::class, ['record' => $home->getRouteKey()])
        ->assertOk()
        ->assertDontSee('/fa/home-page');
});

it('warns in the preview that a draft will not be indexed', function (): void {
    actingAs($this->admin);

    $draft = Content::factory()->create(['status' => ContentStatus::Draft]);

    Livewire::test(EditContent::class, ['record' => $draft->getRouteKey()])
        ->assertOk()
        ->assertSee(__('cms.seo.preview.noindex', ['robots' => 'noindex, nofollow']));
});

it('offers the keyphrase field exactly where the column exists', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->create();
    $page = Page::factory()->create();
    $gallery = Gallery::factory()->create();

    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->assertFormFieldExists('focus_keyphrase.fa')
        ->assertFormFieldExists('focus_keyphrase.en')
        ->assertSee(__('cms.seo.analysis.label'));

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertFormFieldExists('focus_keyphrase.fa');

    /*
     * And NOT on a Gallery. SeoSection decides by asking the model whether it declares
     * the attribute, so this assertion is what stops a list of model classes creeping
     * back into the schema — see HasSeoMetadata::OPTIONAL_SEO_TRANSLATABLE_ATTRIBUTES
     * for why a gallery has no keyphrase.
     */
    Livewire::test(EditGallery::class, ['record' => $gallery->getRouteKey()])
        ->assertOk()
        ->assertDontSee(__('cms.seo.analysis.label'));
});

it('persists a keyphrase per locale and scores against it', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->published()->create();
    $content->setFeaturedImage(MediaAsset::factory()->create());

    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->fillForm([
            'focus_keyphrase.fa' => 'راهنمای خرید',
            'meta_title.fa' => 'راهنمای خرید لپتاپ',
            'focus_keyphrase.en' => 'buying guide',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $content->fresh();

    expect($fresh?->focusKeyphraseFor('fa'))->toBe('راهنمای خرید')
        ->and($fresh?->focusKeyphraseFor('en'))->toBe('buying guide');

    // And the score the panel shows is the model's own answer, not a second
    // implementation living in the form schema.
    $analysis = $fresh?->seoAnalysisFor('fa') ?? [];

    expect(collect($analysis['checks'])->firstWhere('id', 'keyphrase_in_title')['status'])->toBe('pass');
});

it('states in the panel what the analysis does not measure', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->create(['focus_keyphrase' => ['fa' => 'راهنمای خرید']]);

    // A score whose method is hidden becomes superstition, and an editor rewriting
    // sound copy to satisfy a check they misread is worse off than one with no score.
    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->assertOk()
        ->assertSee(__('cms.seo.analysis.caveat'));
});

it('offers the social-card overrides on an article and nowhere else yet', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->create();
    $page = Page::factory()->create();

    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->assertFormFieldExists('og_title.fa')
        ->assertFormFieldExists('og_description.fa');

    // Pages have no og_* columns: the Delivery SEO endpoint is per-article, so the
    // overrides would be fields nothing reads.
    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertOk()
        ->assertDontSee(__('cms.section.social_card'));
});

it('keeps the counters and the advisory warnings working alongside the preview', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->create([
        'meta_title' => ['fa' => ''],
        'title' => ['fa' => 'عنوان'],
        'excerpt' => ['fa' => ''],
        'meta_description' => ['fa' => ''],
    ]);

    // Regression guard: the preview was added at the TOP of the section, above the
    // warnings block that was already there.
    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->assertOk()
        ->assertSee(__('cms.seo.warnings'))
        ->assertSee(__('cms.seo.warning.missing_description'))
        ->assertSee(__('cms.seo.character_count', [
            'count' => 0,
            'limit' => HasSeoMetadata::META_TITLE_ADVISORY_LIMIT,
        ]));
});
