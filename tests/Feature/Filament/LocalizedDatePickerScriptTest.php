<?php

declare(strict_types=1);

use App\Support\Dates\LocalizedDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

/**
 * Exercises the SHIPPED JavaScript of the date picker against ICU.
 *
 * The PHP suite can prove the calendar table is right and that the markup is
 * rendered, but the component that turns that table into a grid — and the date an
 * editor actually clicks into a Gregorian timestamp — is JavaScript, and none of
 * the tests above execute a line of it.
 *
 * So this runs the real module under Node and compares its answers to ICU's.
 * Node is already installed in CI before `php artisan test` (it builds the assets
 * that tests/Architecture/NoExternalCdnTest scans), and the test skips rather than
 * fails where it is absent, so a PHP-only environment is not blocked by it.
 */
beforeEach(function (): void {
    $node = Process::run('node --version');

    if (! $node->successful()) {
        $this->markTestSkipped('Node is not available; the picker script cannot be exercised.');
    }
});

it('agrees with ICU about every date it displays and every date it writes', function (): void {
    $locale = 'fa';
    $table = LocalizedDate::calendarTable(70, 30, $locale);

    expect($table)->not->toBeNull();

    /*
     * Wall clocks in the display timezone — the exact shape Filament's state cast
     * hands the component. Chosen for the boundaries an off-by-one hides in:
     * either side of Nowruz, the 30th of Esfand in a leap year, the 31st of a
     * long month, and the last day the table covers.
     */
    $probeStates = [
        '2026-09-26 12:30:00',
        '2026-03-20 23:59:00',
        '2026-03-21 00:00:00',
        '2025-03-20 10:00:00',
        '2025-03-21 00:30:00',
        '2026-06-21 08:00:00',
        '2026-12-31 21:45:00',
        '1979-02-11 06:00:00',
    ];

    // Persian year/month/day triples the grid will be asked to write back.
    $probeSelections = [
        [1405, 7, 5],
        [1403, 12, 30],
        [1405, 1, 1],
        [1404, 6, 31],
        [1410, 11, 29],
    ];

    $config = [
        'calendar' => $table,
        'closeOnDateSelection' => false,
        'digits' => LocalizedDate::digitGlyphs($locale),
        'firstDayOfWeek' => LocalizedDate::firstDayOfWeek($locale),
        'hasDate' => true,
        'hasSeconds' => false,
        'hasTime' => true,
        'isRequired' => false,
        'maxDate' => null,
        'minDate' => null,
        'monthNames' => array_values(LocalizedDate::monthNames($locale)),
        'state' => null,
        'today' => '2026-09-26 12:30:00',
        'weekdayLabels' => LocalizedDate::weekdayLabels($locale),
        'probeStates' => $probeStates,
        'probeSelections' => $probeSelections,
        // Comfortably before the first year the table covers.
        'outOfRangeState' => '1890-04-15 08:00:00',
    ];

    $driver = base_path('storage/framework/testing/picker-driver.mjs');

    @mkdir(dirname($driver), recursive: true);

    file_put_contents($driver, <<<'JS'
        import component from '../../../resources/js/filament/localized-date-time-picker.js'

        const config = JSON.parse(process.argv[2])
        const picker = component(config)

        // init() is skipped deliberately: it only wires $watch, which needs Alpine.
        const displayed = config.probeStates.map((state) => {
            picker.state = state
            picker.syncFromState()

            return {
                state,
                display: picker.displayText,
                year: picker.focusedYear,
                month: picker.focusedMonth,
                daysInMonth: picker.daysInFocusedMonth,
                leadingBlanks: picker.leadingBlanks,
            }
        })

        const written = config.probeSelections.map(([year, month, day]) => {
            picker.focusedYear = year
            picker.focusedMonth = month
            picker.hour = 9
            picker.minute = 30
            picker.second = 0
            picker.selectDay(day)

            return picker.state
        })

        // Clearing must produce a genuinely empty state, not today's date.
        picker.state = '2026-09-26 12:30:00'
        picker.clearState()
        const cleared = picker.state

        // And touching the clock with no date chosen must not invent one.
        picker.state = null
        picker.hour = 23
        picker.commitTime()
        const timeWithoutDate = picker.state

        /*
         * A date the table cannot address — older than the range it covers. It
         * must still be shown, the grid must fall back to a month it CAN draw, and
         * clicking a day must not write a malformed state.
         */
        picker.state = config.outOfRangeState
        picker.syncFromState()

        const outOfRange = {
            display: picker.displayText,
            focusedYear: picker.focusedYear,
            hasFocusedMonth: picker.hasFocusedMonth,
            daysInMonth: picker.daysInFocusedMonth,
            leadingBlanks: picker.leadingBlanks,
        }

        picker.selectDay(1)
        outOfRange.stateAfterClick = picker.state

        console.log(
            JSON.stringify({ displayed, written, cleared, timeWithoutDate, outOfRange }),
        )
        JS);

    $result = Process::path(dirname($driver))->run(['node', basename($driver), json_encode($config)]);

    expect($result->successful())->toBeTrue($result->errorOutput());

    /** @var array{displayed: list<array<string, mixed>>, written: list<string>, cleared: mixed, timeWithoutDate: mixed} $output */
    $output = json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);

    @unlink($driver);

    /*
     * 1. What the closed field shows must be the date ICU would name.
     *
     * The probe states are wall clocks, so they are parsed IN the display timezone
     * — parsing them as UTC and formatting back would add the offset twice.
     */
    foreach ($output['displayed'] as $row) {
        $instant = CarbonImmutable::parse($row['state'], LocalizedDate::timezone());

        expect($row['display'])->toBe(
            LocalizedDate::format($instant, 'date_time', $locale),
            "display text for {$row['state']}",
        );
    }

    // 2. Month length and grid offset, against ICU rather than against the same
    //    arithmetic the component used.
    foreach ($output['displayed'] as $row) {
        $calendar = IntlCalendar::createInstance(
            new DateTimeZone('UTC'),
            LocalizedDate::icuLocale($locale),
        );
        $calendar->clear();
        $calendar->set(IntlCalendar::FIELD_YEAR, (int) $row['year']);
        $calendar->set(IntlCalendar::FIELD_MONTH, (int) $row['month'] - 1);
        $calendar->set(IntlCalendar::FIELD_DAY_OF_MONTH, 1);

        expect($row['daysInMonth'])
            ->toBe($calendar->getActualMaximum(IntlCalendar::FIELD_DAY_OF_MONTH))
            ->and($row['leadingBlanks'])
            ->toBe(
                ($calendar->get(IntlCalendar::FIELD_DAY_OF_WEEK) - LocalizedDate::firstDayOfWeek($locale) + 7) % 7,
                "leading blanks for {$row['year']}/{$row['month']}",
            );
    }

    // 3. A day clicked in the grid must become the Gregorian wall clock ICU maps
    //    it to — this is the direction that reaches the database.
    foreach ($probeSelections as $index => [$year, $month, $day]) {
        $calendar = IntlCalendar::createInstance(
            new DateTimeZone('UTC'),
            LocalizedDate::icuLocale($locale),
        );
        $calendar->clear();
        $calendar->set(IntlCalendar::FIELD_YEAR, $year);
        $calendar->set(IntlCalendar::FIELD_MONTH, $month - 1);
        $calendar->set(IntlCalendar::FIELD_DAY_OF_MONTH, $day);

        $expected = gmdate('Y-m-d', (int) ($calendar->getTime() / 1000)).' 09:30:00';

        expect($output['written'][$index])->toBe($expected, "selecting {$year}/{$month}/{$day}");
    }

    // 4. Clearing clears, and editing the clock on an empty field does not
    //    silently create a publish date.
    expect($output['cleared'])->toBeNull()
        ->and($output['timeWithoutDate'])->toBeNull();

    /*
     * 5. A date outside the table degrades safely.
     *
     * Content imported from an older archive, or a date set through the Management
     * API, can fall outside the span the picker was handed. It used to leave the
     * grid unresolved: the field rendered EMPTY while a publish_date existed, and
     * clicking any day wrote "0NaN-NaN-NaN 09:00:00" — which Filament's
     * DateTimeStateCast::get() then threw an uncaught InvalidFormatException on.
     * An unreachable date must degrade to a usable picker, never to a corrupted
     * value.
     */
    $outOfRange = $output['outOfRange'];

    expect($outOfRange['display'])->not->toBe('')
        // Shown as stored, so the editor can see the date rather than an empty box
        // they would be tempted to overwrite.
        ->and($outOfRange['display'])->toContain('۱۸۹۰')
        // The grid falls back to a month it can actually draw.
        ->and($outOfRange['hasFocusedMonth'])->toBeTrue()
        ->and($outOfRange['daysInMonth'])->toBeGreaterThan(0)
        ->and($outOfRange['leadingBlanks'])->toBeGreaterThanOrEqual(0)
        // And whatever the click produced, it is something Carbon can read back.
        ->and($outOfRange['stateAfterClick'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

it('covers a wide enough span of years to date an archive', function (): void {
    $table = LocalizedDate::calendarTable(
        (int) config('cms.dates.picker.years_before'),
        (int) config('cms.dates.picker.years_after'),
        'fa',
    );

    expect($table)->not->toBeNull();

    // A news site importing historical material is a normal case, so the default
    // span reaches back past the early twentieth century.
    $firstGregorianYear = (int) CarbonImmutable::createFromTimestamp(
        $table['epochDay'] * 86400,
        'UTC',
    )->format('Y');

    expect($firstGregorianYear)->toBeLessThan(1920);
});
