<?php

declare(strict_types=1);

use App\Support\Dates\CalendarMonth;
use Carbon\CarbonImmutable;

/**
 * Item 43 — the editorial calendar's month grid.
 *
 * The anchors are the ones LocalizedDateTest uses, for the same reason: they are where approximate
 * calendar code goes wrong. Mehr 1405 begins on Wednesday 23 September 2026, at 20:30 UTC the
 * evening before; Esfand 1403 has a 30th day and Esfand 1404 does not.
 */
beforeEach(function (): void {
    config()->set('cms.dates.timezone', 'Asia/Tehran');
    $this->travelTo(CarbonImmutable::parse('2026-09-27 09:00:00', 'UTC'));
});

it('finds the current month in the panel calendar', function (): void {
    $month = CalendarMonth::current('fa');

    expect($month)->not->toBeNull()
        ->and($month->key())->toBe('1405-07')
        ->and($month->label())->toBe('مهر ۱۴۰۵')
        ->and($month->length())->toBe(30);
});

it('bounds a Persian month by its own first instants, not Gregorian ones', function (): void {
    $month = CalendarMonth::of(1405, 7, 'fa');

    // Midnight in Tehran is 20:30 UTC the previous day; Iran has had no summer time since 2022.
    expect($month->start()->toDateTimeString())->toBe('2026-09-22 20:30:00')
        ->and($month->end()->toDateTimeString())->toBe('2026-10-22 20:30:00');
});

it('starts the week on Saturday for Persian and leaves days before the 1st empty', function (): void {
    $weeks = CalendarMonth::of(1405, 7, 'fa')->weeks();

    // Sat, Sun, Mon, Tue are blank; 1 Mehr is the Wednesday.
    expect($weeks[0][0])->toBeNull()
        ->and($weeks[0][3])->toBeNull()
        ->and($weeks[0][4]['day'])->toBe(1)
        ->and($weeks[0][4]['label'])->toBe('۱')
        ->and(collect($weeks)->every(fn (array $week): bool => count($week) === 7))->toBeTrue()
        ->and(collect($weeks)->flatten(1)->filter()->count())->toBe(30);
});

it('marks today', function (): void {
    $today = collect(CalendarMonth::current('fa')->weeks())->flatten(1)->filter()->firstWhere('today', true);

    // 27 September 2026 is 5 Mehr 1405.
    expect($today['day'])->toBe(5);
});

it('gives a leap Esfand its thirtieth day and a common one only twenty-nine', function (): void {
    expect(CalendarMonth::of(1403, 12, 'fa')->length())->toBe(30)
        ->and(CalendarMonth::of(1404, 12, 'fa')->length())->toBe(29);
});

it('steps across the year boundary', function (): void {
    expect(CalendarMonth::of(1404, 12, 'fa')->next()->key())->toBe('1405-01')
        ->and(CalendarMonth::of(1405, 1, 'fa')->previous()->key())->toBe('1404-12');
});

it('refuses months that do not exist or are out of reach', function (): void {
    config()->set('cms.dates.picker.years_before', 1);
    config()->set('cms.dates.picker.years_after', 1);

    expect(CalendarMonth::of(1405, 13, 'fa'))->toBeNull()
        ->and(CalendarMonth::of(1405, 0, 'fa'))->toBeNull()
        ->and(CalendarMonth::of(1400, 1, 'fa'))->toBeNull()
        ->and(CalendarMonth::of(1406, 12, 'fa')->next())->toBeNull()
        ->and(CalendarMonth::of(1404, 1, 'fa')->previous())->toBeNull();
});

it('lays out a Gregorian month for English, starting on Sunday', function (): void {
    $month = CalendarMonth::current('en');

    // 1 September 2026 is a Tuesday: Sunday and Monday are blank.
    expect($month->key())->toBe('2026-09')
        ->and($month->label())->toBe('September 2026')
        ->and($month->weeks()[0][1])->toBeNull()
        ->and($month->weeks()[0][2]['day'])->toBe(1);
});

it('files an instant under the day it falls on in the display timezone', function (): void {
    // 21:00 UTC on 22 September is already 23 September — 1 Mehr — in Tehran.
    $evening = CarbonImmutable::parse('2026-09-22 21:00:00', 'UTC');

    expect(CalendarMonth::epochDayOf($evening))->toBe(CalendarMonth::of(1405, 7, 'fa')->firstEpochDay());
});
