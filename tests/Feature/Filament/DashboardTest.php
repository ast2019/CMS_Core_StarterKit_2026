<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Enums\TranslationStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ContentOverviewWidget;
use App\Filament\Widgets\PublishingActivityWidget;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\TranslationProgressWidget;
use App\Models\ContactSubmission;
use App\Models\Content;
use App\Models\TranslationState;
use App\Models\User;
use App\Support\Dates\LocalizedDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * The panel's landing page, which until now rendered only a version card.
 */
beforeEach(function (): void {
    // Fixed so the month buckets and the "next scheduled" date are deterministic.
    // 4 Mehr 1405; the Persian month runs 23 Sep – 22 Oct.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-26 09:00:00', 'UTC'));

    $this->admin = User::factory()->admin()->withMfaEnrolled()->create();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('greets the editor with today’s date in the panel calendar', function (): void {
    actingAs($this->admin);

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee(__('cms.dashboard.title'))
        // «شنبه ۴ مهر ۱۴۰۵» — the weekday matters as much as the number: it is what
        // makes the relative dates elsewhere on the page legible.
        ->assertSee(LocalizedDate::format(now(), 'weekday'));
});

it('counts live, unfinished and scheduled content separately', function (): void {
    actingAs($this->admin);

    // Live: published, and the date has passed.
    Content::factory()->count(3)->create([
        'status' => ContentStatus::Published,
        'publish_date' => now()->subDay(),
    ]);

    // Scheduled: published, but not yet due. Requirement 3.6 — this must NOT be
    // counted as live, which counting by status alone would do.
    Content::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => CarbonImmutable::parse('2026-09-30 06:30:00', 'UTC'),
    ]);

    Content::factory()->count(2)->create(['status' => ContentStatus::Draft]);
    Content::factory()->create(['status' => ContentStatus::Review]);

    Livewire::test(ContentOverviewWidget::class)
        ->assertOk()
        ->assertSee(__('cms.dashboard.live'))
        // Three live, three in progress, one scheduled — all in Persian digits.
        ->assertSee('۳')
        ->assertSee(__('cms.dashboard.in_progress'))
        ->assertSee(__('cms.dashboard.scheduled'))
        // The scheduled card names the next date rather than leaving the editor to
        // go and look it up. 06:30 UTC is 10:00 in Tehran.
        ->assertSee(__('cms.dashboard.next_publish', [
            'date' => '۸ مهر ۱۴۰۵ - ۱۰:۰۰',
        ]));
});

it('counts only content published inside the current Persian month', function (): void {
    actingAs($this->admin);

    // Mehr 1405 began on 23 September 2026 (20:30 UTC on the 22nd).
    Content::factory()->count(2)->create([
        'status' => ContentStatus::Published,
        'publish_date' => CarbonImmutable::parse('2026-09-24 06:00:00', 'UTC'),
    ]);

    // Shahrivar — the previous Persian month, even though it is the same
    // Gregorian month. A Gregorian "this month" bucket would wrongly include it.
    Content::factory()->count(5)->create([
        'status' => ContentStatus::Published,
        'publish_date' => CarbonImmutable::parse('2026-09-10 06:00:00', 'UTC'),
    ]);

    Livewire::test(ContentOverviewWidget::class)
        ->assertOk()
        ->assertSee(__('cms.dashboard.live_this_month', ['count' => '۲']))
        ->assertDontSee(__('cms.dashboard.live_this_month', ['count' => '۷']));
});

it('shows the unread inbox only to roles that can open it', function (): void {
    ContactSubmission::factory()->count(2)->create(['read_at' => null]);
    ContactSubmission::factory()->create(['read_at' => now()]);

    actingAs($this->admin);

    Livewire::test(ContentOverviewWidget::class)
        ->assertOk()
        ->assertSee(__('cms.dashboard.unread_messages'));

    // An Author has content.view but not contact.view, so the card must be absent
    // rather than present and unusable.
    actingAs(User::factory()->author()->withMfaEnrolled()->create());

    Livewire::test(ContentOverviewWidget::class)
        ->assertOk()
        ->assertDontSee(__('cms.dashboard.unread_messages'));
});

it('buckets the publishing chart on Persian month boundaries', function (): void {
    actingAs($this->admin);

    // Two months, split across a boundary that falls mid-Gregorian-month.
    Content::factory()->count(2)->create([
        'status' => ContentStatus::Published,
        'publish_date' => CarbonImmutable::parse('2026-09-24 06:00:00', 'UTC'),
    ]);
    Content::factory()->count(3)->create([
        'status' => ContentStatus::Published,
        'publish_date' => CarbonImmutable::parse('2026-09-10 06:00:00', 'UTC'),
    ]);

    $widget = new PublishingActivityWidget;

    $data = (fn (): array => $this->getData())->call($widget);

    expect($data['labels'])->toHaveCount(12)
        // Newest last, named in the panel's calendar.
        ->and(end($data['labels']))->toBe('مهر ۱۴۰۵')
        ->and($data['labels'][10])->toBe('شهریور ۱۴۰۵');

    $counts = $data['datasets'][0]['data'];

    expect($counts)->toHaveCount(12)
        ->and($counts[11])->toBe(2)
        ->and($counts[10])->toBe(3)
        // Everything else empty: the two groups must not bleed into a third bucket.
        ->and(array_sum($counts))->toBe(5);
});

it('reports reviewed translations only, per target locale', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->create();

    // Two English rows, one genuinely reviewed and one only machine-translated.
    TranslationState::query()->updateOrCreate(
        ['translatable_type' => $content::class, 'translatable_id' => $content->getKey(), 'locale' => 'en'],
        ['status' => TranslationStatus::Reviewed],
    );

    $second = Content::factory()->create();

    TranslationState::query()->updateOrCreate(
        ['translatable_type' => $second::class, 'translatable_id' => $second->getKey(), 'locale' => 'en'],
        ['status' => TranslationStatus::AiTranslated],
    );

    Livewire::test(TranslationProgressWidget::class)
        ->assertOk()
        ->assertSee('English')
        // 50%, not 100%: unreviewed machine output is not a translation as far as
        // this product is concerned (Decision D-5).
        ->assertSee(LocalizedDate::percent(0.5))
        ->assertSee(__('cms.dashboard.reviewed_ratio', ['reviewed' => '۱', 'total' => '۲']))
        // The source locale is authored, not translated, so it gets no card.
        ->assertDontSee('فارسی');
});

it('keeps the audit summary to roles that may read the audit trail', function (): void {
    expect(RecentActivityWidget::canView())->toBeFalse();

    actingAs($this->admin);
    expect(RecentActivityWidget::canView())->toBeTrue();

    // Editor has content.view and contact.view but not audit.view.
    actingAs(User::factory()->editor()->withMfaEnrolled()->create());
    expect(RecentActivityWidget::canView())->toBeFalse();
});

it('dates the audit summary in Persian', function (): void {
    actingAs($this->admin);

    Content::factory()->create();

    DB::table('activity_log')->update([
        'created_at' => '2026-09-26 09:00:00',
        'updated_at' => '2026-09-26 09:00:00',
    ]);

    Livewire::test(RecentActivityWidget::class)
        ->assertOk()
        ->assertSee(__('cms.dashboard.recent_activity'))
        ->assertSee('۱۴۰۵/۰۷/۰۴ ۱۲:۳۰')
        ->assertDontSee('2026-09-26');
});

it('renders the whole dashboard for every role without error', function (string $role): void {
    actingAs(User::factory()->{$role}()->withMfaEnrolled()->create());

    Content::factory()->create(['status' => ContentStatus::Published, 'publish_date' => now()->subDay()]);

    Livewire::test(Dashboard::class)->assertOk();
})->with(['admin', 'editor', 'author', 'viewer']);

it('does not poll the database behind the dashboard', function (string $widget): void {
    /*
     * Filament's widgets default to `$pollingInterval = '5s'`.
     *
     * Left alone, opening the dashboard re-runs every aggregate on this page —
     * including a twelve-bucket scan of a year of content and a group-by over
     * translation_states — every five seconds, per open tab, forever, to shorten
     * the wait on numbers that change when somebody publishes an article. The panel
     * already makes this call the other way for notifications
     * (`databaseNotificationsPolling(null)` in AdminPanelProvider).
     */
    $interval = (fn (): ?string => $this->getPollingInterval())->call(new $widget);

    expect($interval)->toBeNull();
})->with([
    ContentOverviewWidget::class,
    PublishingActivityWidget::class,
    TranslationProgressWidget::class,
]);

it('counts a month boundary exactly once', function (): void {
    actingAs($this->admin);

    // Mehr 1405 begins at 2026-09-22 20:30 UTC. recentMonths() returns half-open
    // intervals, so an article published at exactly that instant belongs to Mehr and
    // to nothing else — an inclusive whereBetween would count it in both months.
    Content::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => CarbonImmutable::parse('2026-09-22 20:30:00', 'UTC'),
    ]);

    $data = (fn (): array => $this->getData())->call(new PublishingActivityWidget);

    $counts = $data['datasets'][0]['data'];

    expect(array_sum($counts))->toBe(1)
        ->and($counts[11])->toBe(1)
        ->and($counts[10])->toBe(0);

    Livewire::test(ContentOverviewWidget::class)
        ->assertOk()
        ->assertSee(__('cms.dashboard.live_this_month', ['count' => '۱']));
});
