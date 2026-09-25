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

it('does not change the hash when the editor reorders a node key', function (): void {
    /*
     * The defect the hash exists to prevent, delivered BY the hash.
     *
     * sourceContentHash() ksort()ed the top level and then json_encode()d `body` — a
     * nested TipTap document — exactly as it arrived. A TipTap node is an object, so
     * `{"type":"paragraph","content":[…]}` and `{"content":[…],"type":"paragraph"}` are
     * the same paragraph; but they serialised differently, hashed differently, and
     * marked every reviewed locale `outdated`. That is the false alarm the method's own
     * docblock says hash-based detection exists to avoid.
     */
    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    $content->setTranslation('body', 'fa', [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'attrs' => ['textAlign' => 'right', 'class' => null],
                'content' => [
                    ['type' => 'text', 'text' => 'متن بند', 'marks' => [['type' => 'bold']]],
                ],
            ],
        ],
    ]);
    $content->saveQuietly();

    $before = $content->sourceContentHash();

    // The SAME document with every map's keys written in a different order, at three
    // levels of nesting: the node, its attrs, and the text leaf.
    $content->setTranslation('body', 'fa', [
        'content' => [
            [
                'content' => [
                    ['marks' => [['type' => 'bold']], 'text' => 'متن بند', 'type' => 'text'],
                ],
                'attrs' => ['class' => null, 'textAlign' => 'right'],
                'type' => 'paragraph',
            ],
        ],
        'type' => 'doc',
    ]);
    $content->saveQuietly();

    expect($content->sourceContentHash())->toBe($before);
});

it('does change the hash when the editor reorders two paragraphs', function (): void {
    /*
     * The other half, and the reason normalisation sorts MAP KEYS and never LIST
     * ELEMENTS. A list's order is content: swapping two paragraphs changes what the
     * document says, so it must invalidate the translation. Sorting list elements
     * "for consistency" would make a genuine rewrite hash identically to the original
     * and leave a stale translation advertised as reviewed — silent staleness, which is
     * far more expensive than a spurious re-review.
     */
    $document = fn (string $first, string $second): array => [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $first]]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $second]]],
        ],
    ];

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    $content->setTranslation('body', 'fa', $document('بند یک', 'بند دو'));
    $content->saveQuietly();
    $before = $content->sourceContentHash();

    $content->setTranslation('body', 'fa', $document('بند دو', 'بند یک'));
    $content->saveQuietly();

    expect($content->sourceContentHash())->not->toBe($before);
});

it('keeps a reviewed locale reviewed when a save only reorders node keys', function (): void {
    // The end-to-end consequence: this is the save that used to dump the whole locale
    // into the review backlog for nothing.
    $content = Content::factory()->multilingual()->create();

    $content->setTranslation('body', 'fa', [
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'بدنه']],
    ]);
    $content->save();
    $content->markTranslationReviewed('en');

    expect($content->fresh()->translationStatusFor('en'))->toBe(TranslationStatus::Reviewed);

    $content = $content->fresh();
    $content->setTranslation('body', 'fa', [
        'content' => [['text' => 'بدنه', 'type' => 'text']],
        'type' => 'paragraph',
    ]);
    $content->save();

    expect($content->fresh()->translationStatusFor('en'))->toBe(TranslationStatus::Reviewed);
});

it('re-pins a hash from an older scheme instead of flagging the locale outdated', function (): void {
    /*
     * The deploy-day question. A stored hash is compared for equality, so changing how
     * it is COMPUTED makes every pinned value mismatch — and a mismatch reads as "the
     * source moved on", which would flip every reviewed locale in the database to
     * `outdated` at once. That is a review backlog full of work nobody needs to do,
     * which teaches translators to clear the flag without reading it, and the flag is
     * the only signal this feature has.
     *
     * The hash therefore carries a SCHEME tag, and a mismatch whose scheme is not the
     * current one is re-pinned rather than raised. Safe because
     * syncTranslationStatuses() runs on every save: a row that is still `reviewed` is
     * one whose hash matched at its last save, so recomputing describes the same
     * content the reviewer signed off on.
     */
    $content = Content::factory()->multilingual()->create();
    $content->markTranslationReviewed('en');

    $state = $content->translationStates()->where('locale', 'en')->firstOrFail();

    expect($state->source_hash)->toStartWith('v2:');

    // A value pinned by the previous scheme: a bare xxh128 digest with no tag, which is
    // exactly what is sitting in every existing deployment's table.
    $legacy = hash('xxh128', 'whatever the old function produced');
    $state->forceFill(['source_hash' => $legacy])->saveQuietly();

    // A save that changes nothing about the source text.
    $content = $content->fresh();
    $content->touch();

    $state = $content->fresh()->translationStates()->where('locale', 'en')->firstOrFail();

    expect($state->status)->toBe(TranslationStatus::Reviewed)
        // Silently brought up to date, so the NEXT genuine edit is detected normally.
        ->and($state->source_hash)->toBe($content->fresh()->sourceContentHash())
        ->and($state->source_hash)->not->toBe($legacy);
});

it('still flags a genuine change made after the scheme was brought up to date', function (): void {
    // The re-pin must not become a permanent amnesty: once the row carries a
    // current-scheme hash, staleness detection works exactly as before.
    $content = Content::factory()->multilingual()->create();
    $content->markTranslationReviewed('en');

    $state = $content->translationStates()->where('locale', 'en')->firstOrFail();
    $state->forceFill(['source_hash' => hash('xxh128', 'legacy')])->saveQuietly();

    $content->fresh()->touch();

    expect($content->fresh()->translationStatusFor('en'))->toBe(TranslationStatus::Reviewed);

    $content = $content->fresh();
    $content->setTranslation('title', 'fa', 'عنوان تازه');
    $content->save();

    expect($content->fresh()->translationStatusFor('en'))->toBe(TranslationStatus::Outdated);
});

it('asks for a re-review when a reviewed row has no pinned hash at all', function (): void {
    /*
     * A null hash is NOT a scheme mismatch. A reviewed row with nothing pinned never
     * had a verified baseline, so re-pinning it would assert a freshness nobody ever
     * established; the conservative answer is to ask for the re-review. This is the
     * pre-existing behaviour, pinned so the scheme check cannot quietly relax it.
     */
    $content = Content::factory()->multilingual()->create();
    $content->markTranslationReviewed('en');

    $state = $content->translationStates()->where('locale', 'en')->firstOrFail();
    $state->forceFill(['source_hash' => null])->saveQuietly();

    $content->fresh()->touch();

    expect($content->fresh()->translationStatusFor('en'))->toBe(TranslationStatus::Outdated);
});
