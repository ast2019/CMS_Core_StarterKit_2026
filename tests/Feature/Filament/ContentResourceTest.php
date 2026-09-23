<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Contents\Pages\CreateContent;
use App\Filament\Resources\Contents\Pages\EditContent;
use App\Filament\Resources\Contents\Pages\ListContents;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Requirements 3.1, 3.2, 3.3, 4.1, 4.4, 4.5, 5.1.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->withMfaEnrolled()->create();
});

it('renders the content list page', function (): void {
    actingAs($this->admin);

    Livewire::test(ListContents::class)->assertOk();
});

it('renders the content create form with a tab per locale', function (): void {
    actingAs($this->admin);

    // Requirement 5.1/5.2 — all three locales are structural from day one. A
    // form that only rendered Persian would make activating en/ar a code change.
    Livewire::test(CreateContent::class)
        ->assertOk()
        ->assertFormFieldExists('title.fa')
        ->assertFormFieldExists('title.en')
        ->assertFormFieldExists('title.ar')
        ->assertFormFieldExists('body.fa')
        ->assertFormFieldExists('meta_title.fa');
});

it('requires a featured image before an article can be created', function (): void {
    // RULE #7 / Requirement 3.3 — enforced in the form, not only in the model.
    actingAs($this->admin);

    Livewire::test(CreateContent::class)
        ->fillForm([
            'title.fa' => 'یک عنوان آزمایشی',
            'status' => ContentStatus::Draft->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['featured_media_asset_id']);

    expect(Content::count())->toBe(0);
});

it('creates an article with a featured image and generates a Persian slug', function (): void {
    actingAs($this->admin);

    $asset = MediaAsset::factory()->create();

    Livewire::test(CreateContent::class)
        ->fillForm([
            'title.fa' => 'گزارش ویژه آزمایشی',
            'excerpt.fa' => 'خلاصهٔ گزارش',
            'status' => ContentStatus::Draft->value,
            'featured_media_asset_id' => $asset->getKey(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $content = Content::query()->firstOrFail();

    expect($content->getTranslation('title', 'fa'))->toBe('گزارش ویژه آزمایشی')
        // The Persian script must survive into the slug (not be transliterated).
        ->and($content->getTranslation('slug', 'fa'))->toBe('گزارش-ویژه-آزمایشی')
        ->and($content->featuredImage()?->getKey())->toBe($asset->getKey())
        // Authorship is assigned, never chosen, so the .own policy boundary holds.
        ->and($content->author_id)->toBe($this->admin->getKey());
});

it('fills the edit form with every locale rather than repeating the current one', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->create([
        'title' => ['fa' => 'عنوان فارسی', 'en' => 'English title'],
    ]);

    /*
     * The bug this guards against: spatie's getAttributeValue() returns only the
     * current locale, so a form filled from raw attributes shows the Persian text
     * in the English tab and overwrites the real English on save.
     */
    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->assertOk()
        ->assertFormSet([
            'title.fa' => 'عنوان فارسی',
            'title.en' => 'English title',
        ]);
});

it('does not record a blank locale as an existing translation', function (): void {
    actingAs($this->admin);

    $asset = MediaAsset::factory()->create();

    Livewire::test(CreateContent::class)
        ->fillForm([
            'title.fa' => 'فقط فارسی',
            'title.en' => '',
            'status' => ContentStatus::Draft->value,
            'featured_media_asset_id' => $asset->getKey(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $content = Content::query()->firstOrFail();

    // An untouched English tab submits '' — counting that as a translation would
    // start the locale at ai_translated and show it as work already done.
    expect($content->hasAnyTranslationFor('en'))->toBeFalse()
        ->and($content->translationStatusFor('en')->value)->toBe('not_translated');
});

it('denies panel access to a deactivated administrator', function (): void {
    // Requirement 9.1 — Gate::before denies an inactive account before any policy
    // method runs, so a revoked admin cannot fall through to admin bypass.
    $inactive = User::factory()->role(UserRole::Admin)->inactive()->create();

    expect($inactive->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeFalse();
});
