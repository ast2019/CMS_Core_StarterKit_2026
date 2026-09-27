<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Filament\Pages\EditorialCalendar;
use App\Filament\Resources\Contents\ContentResource;
use App\Filament\Resources\Galleries\GalleryResource;
use App\Filament\Resources\Pages\PageResource;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\Page;
use App\Models\User;
use App\Support\AuthorisationProbe;
use App\Support\Dates\CalendarMonth;
use App\Support\EditorialCalendar as Calendar;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Item 43 — the editorial calendar
|--------------------------------------------------------------------------
|
| "Now" is 5 Mehr 1405 (27 September 2026). Mehr runs from 22 September 20:30 UTC to
| 22 October 20:30 UTC, which is what makes the evening-of-the-22nd cases below meaningful: the same
| UTC date is in two different Persian months depending on the hour.
|
*/

beforeEach(function (): void {
    config()->set('cms.dates.timezone', 'Asia/Tehran');
    $this->travelTo(CarbonImmutable::parse('2026-09-27 09:00:00', 'UTC'));
});

/**
 * @param  class-string<Content|Page|Gallery>  $model
 */
function calendarRecord(string $model, string $title, ContentStatus $status, string $publishDateUtc): Content|Page|Gallery
{
    return $model::factory()->create([
        'title' => ['fa' => $title],
        'status' => $status,
        'publish_date' => CarbonImmutable::parse($publishDateUtc, 'UTC'),
    ]);
}

it('renders the current Persian month for anyone who can view content', function (): void {
    // Enrolled, because two-factor is mandatory and the full request below goes through it.
    actingAs(User::factory()->viewer()->withMfaEnrolled()->create());

    Livewire::test(EditorialCalendar::class)
        ->assertOk()
        ->assertSee('مهر ۱۴۰۵')
        ->assertSee('شنبه');

    get(EditorialCalendar::getUrl())->assertOk();
});

it('shows articles, pages and galleries on the day they go out', function (): void {
    actingAs(User::factory()->admin()->create());

    calendarRecord(Content::class, 'خبر زمان‌بندی‌شده', ContentStatus::Published, '2026-10-01 06:00:00');
    calendarRecord(Page::class, 'برگهٔ زمان‌بندی‌شده', ContentStatus::Published, '2026-10-02 06:00:00');
    calendarRecord(Gallery::class, 'گالری زمان‌بندی‌شده', ContentStatus::Published, '2026-10-03 06:00:00');

    Livewire::test(EditorialCalendar::class)
        ->assertSee('خبر زمان‌بندی‌شده')
        ->assertSee('برگهٔ زمان‌بندی‌شده')
        ->assertSee('گالری زمان‌بندی‌شده');
});

it('tells scheduled, published and not-yet-approved records apart', function (): void {
    actingAs(User::factory()->admin()->create());

    calendarRecord(Content::class, 'future published', ContentStatus::Published, '2026-10-01 06:00:00');
    calendarRecord(Content::class, 'past published', ContentStatus::Published, '2026-09-24 06:00:00');
    calendarRecord(Content::class, 'future draft', ContentStatus::Draft, '2026-10-05 06:00:00');
    calendarRecord(Content::class, 'future review', ContentStatus::Review, '2026-10-06 06:00:00');

    $states = collect(Calendar::entriesFor(CalendarMonth::current()))
        ->flatten(1)
        ->pluck('state', 'title');

    expect($states->all())->toBe([
        'past published' => Calendar::STATE_PUBLISHED,
        'future published' => Calendar::STATE_SCHEDULED,
        'future draft' => Calendar::STATE_UNAPPROVED,
        'future review' => Calendar::STATE_UNAPPROVED,
    ]);
});

it('leaves out archived records, stale drafts and deleted records', function (): void {
    actingAs(User::factory()->admin()->create());

    calendarRecord(Content::class, 'archived', ContentStatus::Archived, '2026-10-01 06:00:00');
    // A draft that keeps the date it was once published on is history, not a plan.
    calendarRecord(Content::class, 'stale draft', ContentStatus::Draft, '2026-09-24 06:00:00');
    calendarRecord(Content::class, 'trashed', ContentStatus::Published, '2026-10-01 06:00:00')->delete();

    expect(Calendar::entriesFor(CalendarMonth::current()))->toBe([]);

    Livewire::test(EditorialCalendar::class)
        ->assertDontSee('stale draft')
        ->assertSee(__('cms.editorial_calendar.empty'));
});

it('buckets by the Persian day in Tehran, not by the UTC date', function (): void {
    actingAs(User::factory()->admin()->create());

    // Both are "22 September" in UTC. 20:00 UTC is still 31 Shahrivar in Tehran; 21:00 is 1 Mehr.
    calendarRecord(Content::class, 'last of shahrivar', ContentStatus::Published, '2026-09-22 20:00:00');
    calendarRecord(Content::class, 'first of mehr', ContentStatus::Published, '2026-09-22 21:00:00');

    $mehr = CalendarMonth::of(1405, 7);
    $entries = Calendar::entriesFor($mehr);

    expect(array_keys($entries))->toBe([$mehr->firstEpochDay()])
        ->and($entries[$mehr->firstEpochDay()][0]['title'])->toBe('first of mehr')
        ->and($entries[$mehr->firstEpochDay()][0]['time'])->toBe('۰۰:۳۰');

    $shahrivar = $mehr->previous();

    expect(collect(Calendar::entriesFor($shahrivar))->flatten(1)->pluck('title')->all())
        ->toBe(['last of shahrivar']);
});

it('orders a day by the clock across record types', function (): void {
    actingAs(User::factory()->admin()->create());

    calendarRecord(Gallery::class, 'third', ContentStatus::Published, '2026-10-01 12:00:00');
    calendarRecord(Content::class, 'second', ContentStatus::Published, '2026-10-01 08:00:00');
    calendarRecord(Page::class, 'first', ContentStatus::Published, '2026-10-01 05:00:00');

    expect(collect(Calendar::entriesFor(CalendarMonth::current()))->flatten(1)->pluck('title')->all())
        ->toBe(['first', 'second', 'third']);
});

it('links a title only for someone who may edit it', function (): void {
    $content = calendarRecord(Content::class, 'someone elses article', ContentStatus::Published, '2026-10-01 06:00:00');
    $page = calendarRecord(Page::class, 'a page', ContentStatus::Published, '2026-10-02 06:00:00');
    $gallery = calendarRecord(Gallery::class, 'a gallery', ContentStatus::Published, '2026-10-03 06:00:00');

    $editUrls = [
        ContentResource::getUrl('edit', ['record' => $content]),
        PageResource::getUrl('edit', ['record' => $page]),
        GalleryResource::getUrl('edit', ['record' => $gallery]),
    ];

    actingAs(User::factory()->editor()->create());

    $editor = Livewire::test(EditorialCalendar::class);
    foreach ($editUrls as $url) {
        $editor->assertSeeHtml('href="'.e($url).'"');
    }

    // A Viewer can see the plan but would be sent to a 403 by any edit link.
    actingAs(User::factory()->viewer()->create());

    $viewer = Livewire::test(EditorialCalendar::class)->assertSee('someone elses article');
    foreach ($editUrls as $url) {
        $viewer->assertDontSeeHtml('href="'.e($url).'"');
    }

    // An Author may edit only their own article.
    $author = User::factory()->author()->create();
    $own = Content::factory()->create([
        'title' => ['fa' => 'my article'],
        'status' => ContentStatus::Published,
        'publish_date' => CarbonImmutable::parse('2026-10-04 06:00:00', 'UTC'),
        'author_id' => $author->getKey(),
    ]);

    actingAs($author);

    Livewire::test(EditorialCalendar::class)
        ->assertSeeHtml('href="'.e(ContentResource::getUrl('edit', ['record' => $own])).'"')
        ->assertDontSeeHtml('href="'.e($editUrls[0]).'"');
});

it('does not audit deciding whether to draw a link as a refused edit', function (): void {
    actingAs($author = User::factory()->author()->create());

    calendarRecord(Content::class, 'colleague 1', ContentStatus::Published, '2026-10-01 06:00:00');
    calendarRecord(Content::class, 'colleague 2', ContentStatus::Published, '2026-10-02 06:00:00');

    Livewire::test(EditorialCalendar::class)->assertSee('colleague 1');

    expect(Activity::query()->where('event', 'denied')->count())->toBe(0);

    // A real check outside the probe is still a security signal, and still recorded.
    expect($author->can('update', Content::query()->firstOrFail()))->toBeFalse()
        ->and(Activity::query()->where('event', 'denied')->count())->toBe(1);
});

it('keeps an unapproved record in view, as missed, once its time today has passed', function (): void {
    actingAs(User::factory()->admin()->create());

    // 10:00 in Tehran on 5 Mehr; "now" is 12:30 there.
    calendarRecord(Content::class, 'late review', ContentStatus::Review, '2026-09-27 06:30:00');

    $this->travelTo(CarbonImmutable::parse('2026-09-27 06:00:00', 'UTC'));
    expect(collect(Calendar::entriesFor(CalendarMonth::current()))->flatten(1)->pluck('state')->all())
        ->toBe([Calendar::STATE_UNAPPROVED]);

    $this->travelTo(CarbonImmutable::parse('2026-09-27 07:00:00', 'UTC'));
    expect(collect(Calendar::entriesFor(CalendarMonth::current()))->flatten(1)->pluck('state')->all())
        ->toBe([Calendar::STATE_MISSED]);

    // Tomorrow it is history, like any draft with a past date.
    $this->travelTo(CarbonImmutable::parse('2026-09-28 07:00:00', 'UTC'));
    expect(Calendar::entriesFor(CalendarMonth::current()))->toBe([]);
});

it('bounds months in the configured timezone, whichever it is', function (): void {
    config()->set('cms.dates.timezone', 'America/New_York');
    actingAs(User::factory()->admin()->create());

    // 1 Mehr 1405 begins at midnight in New York, 04:00 UTC in summer time.
    calendarRecord(Content::class, 'before', ContentStatus::Published, '2026-09-23 03:59:00');
    calendarRecord(Content::class, 'after', ContentStatus::Published, '2026-09-23 04:00:00');

    $mehr = CalendarMonth::of(1405, 7);

    expect($mehr->start()->toDateTimeString())->toBe('2026-09-23 04:00:00')
        ->and(collect(Calendar::entriesFor($mehr))->flatten(1)->pluck('title')->all())->toBe(['after']);
});

it('counts the folded entries in English too', function (): void {
    app()->setLocale('en');
    actingAs(User::factory()->admin()->create());

    foreach (range(1, 4) as $hour) {
        // 29 September: in the Gregorian grid this is the current month, unlike 1 October.
        calendarRecord(Content::class, "busy {$hour}", ContentStatus::Published, "2026-09-29 0{$hour}:00:00");
    }

    Livewire::test(EditorialCalendar::class)
        ->assertSee('September 2026')
        ->assertSee('One more');
});

it('shows a notice instead of a grid for a calendar whose years restart by era', function (): void {
    config()->set('cms.dates.calendars.fa', 'japanese');
    actingAs(User::factory()->admin()->create());

    expect(CalendarMonth::current())->toBeNull();

    Livewire::test(EditorialCalendar::class)
        ->assertOk()
        ->assertSee(__('cms.editorial_calendar.unavailable'));
});

it('releases the quiet probe even when the check throws', function (): void {
    expect(fn () => AuthorisationProbe::quietly(fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    expect(AuthorisationProbe::isQuiet())->toBeFalse();
});

it('steps between months and back to the current one', function (): void {
    actingAs(User::factory()->admin()->create());

    calendarRecord(Content::class, 'in aban', ContentStatus::Published, '2026-10-25 06:00:00');

    Livewire::test(EditorialCalendar::class)
        ->assertDontSee('in aban')
        ->call('nextMonth')
        ->assertSet('month', '1405-08')
        ->assertSee('آبان ۱۴۰۵')
        ->assertSee('in aban')
        ->call('previousMonth')
        ->call('previousMonth')
        ->assertSet('month', '1405-06')
        ->assertSee('شهریور ۱۴۰۵')
        ->call('currentMonth')
        ->assertSet('month', null)
        ->assertSee('مهر ۱۴۰۵');
});

it('falls back to the current month for a month that does not exist', function (string $month): void {
    actingAs(User::factory()->admin()->create());

    Livewire::withQueryParams(['month' => $month])
        ->test(EditorialCalendar::class)
        ->assertOk()
        ->assertSee('مهر ۱۴۰۵');
})->with(['1405-13', 'nonsense', '9999-01', '']);

it('folds a busy day behind a counted "more"', function (): void {
    actingAs(User::factory()->admin()->create());

    foreach (range(1, 5) as $hour) {
        calendarRecord(Content::class, "busy {$hour}", ContentStatus::Published, "2026-10-01 0{$hour}:00:00");
    }

    Livewire::test(EditorialCalendar::class)
        ->assertSee('busy 5')
        ->assertSee('۲ مورد دیگر');
});

it('is closed to an account that cannot view content', function (): void {
    actingAs(User::factory()->inactive()->create());

    expect(EditorialCalendar::canAccess())->toBeFalse();
});
