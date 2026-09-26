<?php

declare(strict_types=1);

use App\Support\Dates\LocalizedDate;
use Carbon\CarbonImmutable;

/**
 * The calendar layer everything else formats through.
 *
 * The date pairs below are anchors chosen because they are the cases an
 * approximate Jalali implementation gets wrong:
 *
 *  - 22 Bahman 1357 is the Islamic Revolution, the most widely published
 *    Gregorian/Persian date pair there is.
 *  - 1403 is a leap year, so it has a 30th of Esfand. Cycle-based arithmetic
 *    drops it, which is the single most common Jalali bug.
 *  - 2026-03-20/21 straddles Nowruz, so an off-by-one in the year boundary shows
 *    up as the wrong YEAR rather than the wrong day.
 *  - 2026-07-01 is in summer, when the ICU build bundled with PHP still believes
 *    Iran observes daylight saving. It does not, and has not since 2022.
 */
it('renders an instant in the calendar of each locale', function (
    string $utc,
    string $fa,
    string $en,
    string $ar,
): void {
    $instant = CarbonImmutable::parse($utc, 'UTC');

    expect(LocalizedDate::format($instant, 'date', 'fa'))->toBe($fa)
        ->and(LocalizedDate::format($instant, 'date', 'en'))->toBe($en)
        ->and(LocalizedDate::format($instant, 'date', 'ar'))->toBe($ar);
})->with([
    'Islamic Revolution' => ['1979-02-11 09:00:00', '۱۳۵۷/۱۱/۲۲', '1979/02/11', '١٩٧٩/٠٢/١١'],
    'last day of 1404' => ['2026-03-20 09:00:00', '۱۴۰۴/۱۲/۲۹', '2026/03/20', '٢٠٢٦/٠٣/٢٠'],
    'Nowruz 1405' => ['2026-03-21 09:00:00', '۱۴۰۵/۰۱/۰۱', '2026/03/21', '٢٠٢٦/٠٣/٢١'],
    '30 Esfand in a leap year' => ['2025-03-20 09:00:00', '۱۴۰۳/۱۲/۳۰', '2025/03/20', '٢٠٢٥/٠٣/٢٠'],
]);

it('shifts an instant into the display timezone before naming the day', function (): void {
    // 20:30 UTC is 00:00 in Tehran, so this instant belongs to the NEXT Persian
    // day. Formatting the UTC reading would show editors the previous date for
    // every evening publication.
    expect(LocalizedDate::format('2026-09-26 20:30:00', 'date_time', 'fa'))
        ->toBe('۱۴۰۵/۰۷/۰۵ ۰۰:۰۰')
        ->and(LocalizedDate::format('2026-09-26 19:30:00', 'date_time', 'fa'))
        ->toBe('۱۴۰۵/۰۷/۰۴ ۲۳:۰۰');
});

it('uses the system timezone database rather than the one bundled with ICU', function (): void {
    /*
     * Iran abolished daylight saving in 2022, and PHP's tzdata knows it. The ICU
     * build in this image does not, and asked to render this instant in
     * Asia/Tehran it answers 16:30. If this test fails with a one-hour error,
     * LocalizedDate has started letting ICU do the timezone arithmetic again.
     */
    expect(LocalizedDate::format('2026-07-01 12:00:00', 'time', 'fa'))->toBe('۱۵:۳۰');
});

it('returns null for a blank or unparseable value', function (mixed $value): void {
    expect(LocalizedDate::format($value))->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
    'not a date' => ['definitely not a date'],
]);

it('reads month and weekday names out of ICU, in calendar order', function (): void {
    expect(LocalizedDate::monthNames('fa'))
        ->toBe([
            1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
            5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
            9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
        ])
        // Saturday-first, because that is where a Persian week begins. A grid
        // starting on Monday puts every day under the wrong heading.
        ->and(LocalizedDate::weekdayLabels('fa'))->toBe(['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'])
        ->and(LocalizedDate::firstDayOfWeek('fa'))->toBe(7)
        ->and(LocalizedDate::firstDayOfWeek('en'))->toBe(1);
});

it('localises digits in text it did not format', function (): void {
    // Carbon emits ASCII digits whatever its locale, so relative time has to be
    // passed through the digit map.
    expect(LocalizedDate::human(CarbonImmutable::now()->subDays(3), 'fa'))->toBe('۳ روز پیش')
        ->and(LocalizedDate::digits('نسخه 2.1.0', 'fa'))->toBe('نسخه ۲.۱.۰')
        ->and(LocalizedDate::digits('version 2.1.0', 'en'))->toBe('version 2.1.0')
        ->and(LocalizedDate::number(12345, 'en'))->toBe('12,345');
});

it('describes the calendar of each locale', function (): void {
    expect(LocalizedDate::calendar('fa'))->toBe('persian')
        ->and(LocalizedDate::isGregorian('fa'))->toBeFalse()
        // Arabic is a language, not a calendar: Gregorian months in Arabic words
        // and Arabic-Indic digits, which is how Arabic-language news is dated.
        ->and(LocalizedDate::calendar('ar'))->toBe('gregorian')
        ->and(LocalizedDate::isGregorian('ar'))->toBeTrue()
        ->and(LocalizedDate::icuLocale('fa'))->toBe('fa@calendar=persian;numbers=arabext');
});

it('honours a configured calendar it does not ship a default for', function (): void {
    config()->set('cms.dates.calendars.ar', 'islamic-umalqura');

    expect(LocalizedDate::format('2026-09-26 09:00:00', 'date', 'ar'))->toBe('١٤٤٨/٠٤/١٥')
        ->and(LocalizedDate::isGregorian('ar'))->toBeFalse();
});

it('buckets months on the calendar’s own boundaries', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-26 09:00:00', 'UTC'));

    $months = LocalizedDate::recentMonths(3, 'fa');

    expect($months)->toHaveCount(3)
        ->and($months[2]['label'])->toBe('مهر ۱۴۰۵');

    /*
     * The point of the whole method: Mehr 1405 begins on 23 September, not on the
     * 1st of any Gregorian month. A Gregorian bucket splits every Persian month
     * across two bars of a chart.
     */
    expect($months[2]['start']->toDateTimeString())->toBe('2026-09-22 20:30:00')
        ->and($months[2]['end']->toDateTimeString())->toBe('2026-10-22 20:30:00');

    // Half-open and contiguous, so no day is counted twice or missed.
    expect($months[1]['end']->toDateTimeString())->toBe($months[2]['start']->toDateTimeString());

    CarbonImmutable::setTestNow();
});

it('falls back to the application timezone when the configured one is nonsense', function (): void {
    config()->set('cms.dates.timezone', 'Mars/Olympus_Mons');

    // A mistyped env var must degrade to UTC dates, not throw on every page.
    expect(LocalizedDate::timezone()->getName())->toBe('UTC')
        ->and(LocalizedDate::format('2026-09-26 20:30:00', 'date_time', 'fa'))
        ->toBe('۱۴۰۵/۰۷/۰۴ ۲۰:۳۰');
});

/*
|--------------------------------------------------------------------------
| The calendar table handed to the browser
|--------------------------------------------------------------------------
*/

it('encodes a calendar table that agrees with ICU on every single day', function (): void {
    $table = LocalizedDate::calendarTable(70, 30, 'fa');

    expect($table)->not->toBeNull();

    /*
     * This replays the lookup the Alpine component performs — cumulative month
     * lengths, then a search for the month containing a day number — and checks
     * every day in the table against ICU.
     *
     * It is the test that makes the client-side picker trustworthy. The panel
     * cannot ask ICU which years are leap, so it is sent a table instead; if that
     * table and ICU ever disagree, the grid offers a date the server then refuses,
     * and only for a few years scattered across the range. Checking a handful of
     * sample dates would not find it.
     */
    $lengths = ['8' => 28, '9' => 29, '0' => 30, '1' => 31];

    $starts = [$table['epochDay']];

    for ($index = 0; $index < strlen($table['months']); $index++) {
        $starts[$index + 1] = $starts[$index] + $lengths[$table['months'][$index]];
    }

    $monthCount = count($starts) - 1;

    $reference = new IntlDateFormatter(
        'fa@calendar=persian;numbers=latn',
        IntlDateFormatter::NONE,
        IntlDateFormatter::NONE,
        new DateTimeZone('UTC'),
        IntlDateFormatter::TRADITIONAL,
        'yyyy-MM-dd',
    );

    $mismatches = [];

    for ($day = $starts[0]; $day < $starts[$monthCount]; $day++) {
        // Binary search, exactly as the JavaScript does.
        $low = 0;
        $high = $monthCount - 1;

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);

            if ($starts[$middle] <= $day) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        $mine = sprintf(
            '%04d-%02d-%02d',
            $table['firstYear'] + intdiv($low, 12),
            ($low % 12) + 1,
            $day - $starts[$low] + 1,
        );

        $icu = $reference->format(new DateTimeImmutable('@'.($day * 86400)));

        if ($mine !== $icu) {
            $mismatches[] = "day {$day}: table={$mine} icu={$icu}";

            if (count($mismatches) > 5) {
                break;
            }
        }
    }

    expect($mismatches)->toBe([])
        ->and($monthCount)->toBe(101 * 12);
});

it('formats a percentage with the locale’s own sign, not a hardcoded one', function (): void {
    // «٪» is right for Persian and Arabic and wrong for English, so the sign comes
    // from ICU rather than from a string literal.
    expect(LocalizedDate::percent(0.45, 'fa'))->toBe('۴۵٪')
        ->and(LocalizedDate::percent(0.45, 'en'))->toBe('45%')
        ->and(LocalizedDate::percent(1.0, 'fa'))->toBe('۱۰۰٪');
});

it('refuses to encode a calendar the table cannot represent', function (): void {
    // Hebrew years have 12 or 13 months, so (year * 12 + month) does not address
    // them. Returning null is what makes the picker fall back instead of drawing a
    // wrong calendar.
    config()->set('cms.dates.calendars.fa', 'hebrew');

    expect(LocalizedDate::calendarTable(5, 5, 'fa'))->toBeNull();
});
