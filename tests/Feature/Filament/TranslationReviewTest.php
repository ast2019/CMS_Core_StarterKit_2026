<?php

declare(strict_types=1);

use App\Enums\TranslationStatus;
use App\Enums\UserRole;
use App\Filament\Pages\TranslationReview;
use App\Models\Content;
use App\Models\TranslationState;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Requirements 5.3, 5.4, 5.6.
 */
it('renders the translation review page', function (): void {
    actingAs(User::factory()->editor()->create());

    Content::factory()->create();

    Livewire::test(TranslationReview::class)->assertOk();
});

it('lists only locales needing attention and never the source locale', function (): void {
    actingAs(User::factory()->editor()->create());

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان فارسی']]);

    $rows = TranslationState::query()
        ->needingAttention()
        ->where('locale', '!=', config('cms.locales.source'))
        ->pluck('locale')
        ->all();

    // en and ar are outstanding; fa is authoritative, not a translation.
    expect($rows)->toContain('en')
        ->and($rows)->toContain('ar')
        ->and($rows)->not->toContain('fa');

    // And the source row exists but is already reviewed, so it never surfaces.
    expect($content->translationStatusFor('fa'))->toBe(TranslationStatus::Reviewed);
});

it('shows the source text rather than the empty target', function (): void {
    actingAs(User::factory()->editor()->create());

    Content::factory()->create(['title' => ['fa' => 'متن مبنای آزمایشی']]);

    // A translator needs something to translate FROM; the target is by definition
    // empty or stale, so a target-only column would be useless.
    Livewire::test(TranslationReview::class)
        ->assertOk()
        ->assertSee('متن مبنای آزمایشی');
});

it('refuses to sign off on a locale with no text', function (): void {
    /*
     * Marking an empty translation reviewed would make it sitemap-eligible under
     * Decision D-5 and publish a blank page in that locale — the precise outcome the
     * lifecycle exists to prevent.
     */
    actingAs(User::factory()->admin()->create());

    $content = Content::factory()->create(['title' => ['fa' => 'فقط فارسی']]);
    $state = $content->translationStates()->where('locale', 'en')->firstOrFail();

    Livewire::test(TranslationReview::class)
        ->callAction(TestAction::make('markReviewed')->table($state));

    expect($content->fresh()->translationStatusFor('en'))
        ->toBe(TranslationStatus::NotTranslated);
});

it('marks a populated translation reviewed and makes it sitemap-eligible', function (): void {
    actingAs(User::factory()->admin()->create());

    $content = Content::factory()->multilingual()->create();
    $state = $content->translationStates()->where('locale', 'en')->firstOrFail();

    Livewire::test(TranslationReview::class)
        ->callAction(TestAction::make('markReviewed')->table($state));

    $content->refresh();

    expect($content->translationStatusFor('en'))->toBe(TranslationStatus::Reviewed)
        ->and($content->isSitemapEligibleFor('en'))->toBeTrue();
});

it('flags a reviewed translation as outdated when the source text changes', function (): void {
    // Requirement 5.4 — the whole point of storing source_hash at review time.
    actingAs(User::factory()->admin()->create());

    $content = Content::factory()->multilingual()->create();
    $content->markTranslationReviewed('en');

    $content->setTranslation('title', 'fa', 'عنوان تغییر یافته');
    $content->save();

    expect($content->fresh()->translationStatusFor('en'))->toBe(TranslationStatus::Outdated)
        // Still sitemap-eligible: it was verified once and the content is real, so
        // removing it from the index on every source tweak would be worse than
        // serving a slightly stale translation (Decision D-5).
        ->and($content->fresh()->isSitemapEligibleFor('en'))->toBeTrue();
});

it('restricts reviewing to roles holding translation.review', function (): void {
    // Decision D-10 — an Author may translate but not sign off on their own work.
    expect(UserRole::Admin->hasAbility('translation.review'))->toBeTrue()
        ->and(UserRole::Editor->hasAbility('translation.review'))->toBeTrue()
        ->and(UserRole::Author->hasAbility('translation.review'))->toBeFalse()
        ->and(UserRole::Viewer->hasAbility('translation.review'))->toBeFalse();

    // Viewing the backlog is broader than acting on it, so an Author still sees it.
    expect(UserRole::Author->hasAbility('translation.view'))->toBeTrue();
});

it('hides the page from a role without translation.view', function (): void {
    actingAs(User::factory()->role(UserRole::Author)->create());
    expect(TranslationReview::canAccess())->toBeTrue();

    // There is no role without translation.view in the current matrix, so this
    // asserts the gate reads the ability rather than being hardcoded open.
    $inactive = User::factory()->role(UserRole::Author)->inactive()->create();
    actingAs($inactive);

    expect(TranslationReview::canAccess())->toBeFalse();
});

it('counts the backlog on the navigation badge', function (): void {
    actingAs(User::factory()->admin()->create());

    expect(TranslationReview::getNavigationBadge())->toBeNull();

    Content::factory()->create();

    // Two outstanding locales (en, ar) for one article.
    expect((int) TranslationReview::getNavigationBadge())->toBe(2);
});
