<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Filament\Forms\Components\LocalizedDateTimePicker;
use App\Filament\Pages\AuditLog;
use App\Filament\Resources\ContactSubmissions\Pages\ListContactSubmissions;
use App\Filament\Resources\Contents\Pages\CreateContent;
use App\Filament\Resources\Contents\Pages\EditContent;
use App\Filament\Resources\Contents\Pages\ListContents;
use App\Filament\Widgets\VersionWidget;
use App\Models\Changelog;
use App\Models\ContactSubmission;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\SystemInfo;
use App\Models\User;
use App\Support\Dates\LocalizedDate;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Persian dates everywhere an editor can see one.
 *
 * The panel runs at `fa` (RULE #5), so every assertion here is about the Persian
 * calendar. The dates are fixed instants rather than relative ones so a failure
 * names a wrong date instead of drifting with the clock.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->withMfaEnrolled()->create();

    // 2026-09-26 09:00 UTC is 12:30 on 4 Mehr 1405 in Tehran.
    $this->instant = CarbonImmutable::parse('2026-09-26 09:00:00', 'UTC');
});

/*
|--------------------------------------------------------------------------
| Listings
|--------------------------------------------------------------------------
*/

it('lists content with a Persian publish date', function (): void {
    actingAs($this->admin);

    Content::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => $this->instant,
    ]);

    Livewire::test(ListContents::class)
        ->assertOk()
        ->assertSee('۱۴۰۵/۰۷/۰۴ ۱۲:۳۰')
        // The Gregorian rendering must be gone, not merely accompanied.
        ->assertDontSee('2026-09-26');
});

it('shows the audit trail in Persian, to the second', function (): void {
    actingAs($this->admin);

    Content::factory()->create(['publish_date' => $this->instant]);

    // The activity row's own created_at, not the content's publish date.
    DB::table('activity_log')->update([
        'created_at' => '2026-09-26 09:00:07',
        'updated_at' => '2026-09-26 09:00:07',
    ]);

    Livewire::test(AuditLog::class)
        ->assertOk()
        // Seconds are kept in the forensic view: two writes in the same minute
        // have to be orderable by eye.
        ->assertSee('۱۴۰۵/۰۷/۰۴ ۱۲:۳۰:۰۷');
});

it('dates a contact submission in Persian', function (): void {
    actingAs($this->admin);

    $submission = ContactSubmission::factory()->create();

    DB::table('contact_submissions')
        ->where('id', $submission->getKey())
        ->update(['created_at' => '2026-09-26 09:00:00']);

    Livewire::test(ListContactSubmissions::class)
        ->assertOk()
        ->assertSee('۱۴۰۵/۰۷/۰۴ ۱۲:۳۰');
});

it('dates the version widget in Persian and keeps the SemVer in Latin', function (): void {
    actingAs($this->admin);

    SystemInfo::current()->update([
        'version' => '1.2.3',
        'installed_at' => $this->instant,
    ]);

    Changelog::create([
        'version' => '1.2.3',
        'released_at' => $this->instant,
        'entries' => ['added' => ['Localised dates']],
    ]);

    Livewire::test(VersionWidget::class)
        ->assertOk()
        ->assertSee('۱۴۰۵/۰۷/۰۴')
        // A semantic version is a Latin token and stays one.
        ->assertSee('1.2.3');
});

/*
|--------------------------------------------------------------------------
| The picker
|--------------------------------------------------------------------------
*/

it('renders a Persian calendar picker on the content form', function (): void {
    actingAs($this->admin);

    $html = Livewire::test(CreateContent::class)
        ->assertOk()
        ->html();

    expect($html)
        // Our Alpine component, not Filament's.
        ->toContain('localizedDateTimePickerFormComponent')
        ->toContain('cms-localized-date-time-picker')
        // The month names come from ICU, so the grid is genuinely Persian rather
        // than Gregorian months with Persian labels.
        ->toContain('فروردین')
        ->toContain('اسفند')
        // Persian digit glyphs are handed to the component rather than mapped in JS.
        ->toContain('۰۱۲۳۴۵۶۷۸۹')
        // Saturday-first week. Laravel's Js::from() hex-escapes quotes, so the
        // config arrives as JSON.parse('{\u0022…') inside the attribute.
        ->toContain('firstDayOfWeek\u0022:7')
        /*
         * The calendar table, so the browser never has to convert a calendar.
         * Derived from config rather than hardcoded: the span is tunable, and a
         * literal year here would make this test fail for a reason that has
         * nothing to do with what it is checking.
         */
        ->toContain('firstYear\u0022:'.LocalizedDate::calendarTable(
            (int) config('cms.dates.picker.years_before'),
            (int) config('cms.dates.picker.years_after'),
            'fa',
        )['firstYear'])
        // Filament's own panel classes are reused, so the grid is styled by
        // Filament's stylesheet rather than a copy of it.
        ->toContain('fi-fo-date-time-picker-calendar')
        ->toContain(__('cms.date.today'))
        // The OS-level control cannot draw a Persian month, so it must be gone.
        ->not->toContain('type="datetime-local"')
        // Filament's Gregorian, Day.js-based component must not also be mounted.
        ->not->toContain('dateTimePickerFormComponent({');
});

it('shows an existing publish date in the picker as a Persian date', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->create(['publish_date' => $this->instant]);

    // The state the Alpine component receives is a Gregorian wall clock in the
    // display timezone — Filament's own state cast produces it, and the component
    // converts it to Persian for display. 09:00 UTC is 12:30 in Tehran.
    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->assertOk()
        ->assertSet('data.publish_date', '2026-09-26 12:30:00');
});

it('stores a date chosen in the panel timezone as UTC', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->create(['publish_date' => $this->instant]);

    // RULE #7 — the form rejects a save without a featured image.
    $content->setFeaturedImage(MediaAsset::factory()->create());

    /*
     * The editor picks 09:00 on 5 Mehr — which the component writes back as the
     * Gregorian wall clock 2026-09-27 09:00 — and the row must hold 05:30 UTC.
     *
     * This is the half of the feature that is invisible until it is wrong: before
     * the display timezone was configured, a time typed into the panel was treated
     * as UTC, so an article scheduled for nine in the morning went live at half
     * past twelve.
     */
    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->set('data.publish_date', '2026-09-27 09:00:00')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($content->refresh()->publish_date?->utc()->toDateTimeString())
        ->toBe('2026-09-27 05:30:00')
        // And it reads back to the editor as the date they chose.
        ->and(LocalizedDate::format($content->publish_date, 'date_time'))
        ->toBe('۱۴۰۵/۰۷/۰۵ ۰۹:۰۰');
});

it('hands the form back to Filament for a Gregorian locale', function (): void {
    actingAs($this->admin);

    // An English panel has no reason to run an imitation of Filament's picker.
    config()->set('cms.dates.calendars.fa', 'gregorian');

    $picker = LocalizedDateTimePicker::make('publish_date');

    expect($picker->isNative())->toBeTrue();

    $html = Livewire::test(CreateContent::class)->assertOk()->html();

    expect($html)->not->toContain('localizedDateTimePickerFormComponent');
});
