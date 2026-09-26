<?php

declare(strict_types=1);

namespace App\Support\Dates;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use IntlCalendar;
use IntlDateFormatter;
use NumberFormatter;
use Throwable;

/**
 * Turns an instant into text in the calendar, digits and timezone of a locale.
 *
 * This is the single place the CMS decides that a Persian reader sees
 * «۵ مهر ۱۴۰۵» where an English one sees «26 September 2026». The admin panel,
 * the dashboard widgets and the Delivery API all format through here, so there is
 * one answer to "what does a date look like in this locale" rather than one per
 * call site. Configuration lives in `config/cms.php` under `dates`.
 *
 * ---------------------------------------------------------------------------
 * Why ICU and not a Jalali package
 * ---------------------------------------------------------------------------
 * ext-intl is already a hard requirement of this application — the Dockerfile
 * installs it with the comment "locale-aware formatting for fa/en/ar", and
 * `composer install` validates it as a platform requirement. Given that, a
 * Jalali library would be a second, narrower implementation of something already
 * present, and a worse one:
 *
 *  - ICU's Persian calendar is ASTRONOMICAL. The widely copied 33-year-cycle
 *    arithmetic disagrees with it on leap years, and the disagreement is not
 *    theoretical: 1403 has an 30th of Esfand, which cycle-based code drops.
 *  - ICU supplies the month names, weekday names and digit glyphs for fa, en and
 *    ar. A date package supplies Persian only, so the English and Arabic locales
 *    this kit ships structurally would need separate handling anyway.
 *  - Changing calendar is then configuration, not code: a site that wants Hijri
 *    for Arabic sets one key. Nothing here knows the word "Jalali".
 *
 * ---------------------------------------------------------------------------
 * Why PHP converts the timezone and ICU only converts the calendar
 * ---------------------------------------------------------------------------
 * ICU carries its OWN copy of the timezone database, and it is usually older
 * than the system's. On this image ICU 67.1 ships 2020-era rules while PHP has
 * 2026.3, and the difference is a live bug rather than a curiosity: ICU still
 * believes Iran observes summer time, which Iran abolished in 2022. Asked to
 * render 2026-07-01T12:00Z in Asia/Tehran, ICU answers 16:30 and PHP answers
 * 15:30, and PHP is right.
 *
 * So the two jobs are split. PHP — whose tzdata is maintained by the base image —
 * shifts the instant into the display timezone. The resulting wall-clock reading
 * is then handed to ICU labelled UTC, so ICU performs no timezone arithmetic at
 * all and is used only for what it is uniquely good at: turning a Gregorian
 * date into another calendar, in the right language, with the right digits.
 *
 * The seam is `wallClock()` / `fromWallClock()`, and every public method here is
 * on one side of it or the other.
 */
final class LocalizedDate
{
    /**
     * Digit glyphs per ICU numbering system, for the numbering systems this kit's
     * locales use.
     *
     * Applied with strtr() to text ICU did not produce — Carbon's relative-time
     * phrases, mainly. Anything formatted through IntlDateFormatter or
     * NumberFormatter already comes back in the right digits and must not be
     * passed through here twice.
     *
     * A numbering system absent from this map is left in ASCII, which is correct
     * for `latn` and a safe degradation for anything exotic.
     *
     * @var array<string, string>
     */
    private const DIGITS = [
        'arabext' => '۰۱۲۳۴۵۶۷۸۹',
        'arab' => '٠١٢٣٤٥٦٧٨٩',
        'latn' => '0123456789',
    ];

    private const ASCII_DIGITS = '0123456789';

    /**
     * Month lengths, encoded one character each, for calendarTable().
     *
     * A hundred years of month lengths is 1,200 numbers; as JSON integers that is
     * several kilobytes shipped into every form that has a date field, and as
     * single characters it is 1.2 KB. A length missing from this map makes
     * calendarTable() return null rather than guess — a wrong month length would
     * silently shift every later date in the table.
     *
     * @var array<int, string>
     */
    private const MONTH_LENGTH_CODES = [
        28 => '8',
        29 => '9',
        30 => '0',
        31 => '1',
    ];

    /**
     * IntlDateFormatter is expensive enough to matter: the audit log renders 100
     * rows per page and each one formats a timestamp. Keyed by every input that
     * affects the result, so a config change in a test produces a different key
     * rather than a stale hit.
     *
     * @var array<string, IntlDateFormatter>
     */
    private static array $formatters = [];

    /**
     * @var array<string, NumberFormatter>
     */
    private static array $numberFormatters = [];

    private function __construct() {}

    /**
     * Format an instant in a locale's calendar.
     *
     * $pattern is either a name from `cms.dates.patterns` or a raw ICU pattern.
     * Unknown names fall through as raw patterns, which is what makes a one-off
     * format at a call site possible without adding a config key for it.
     *
     * Returns null for a blank or unparseable value, so a nullable column can be
     * passed straight in and the caller decides what an absent date looks like.
     */
    public static function format(
        DateTimeInterface|string|int|null $value,
        string $pattern = 'date_time',
        ?string $locale = null,
    ): ?string {
        $instant = self::instant($value);

        if ($instant === null) {
            return null;
        }

        $formatted = self::formatter(self::locale($locale), self::pattern($pattern))
            ->format(self::wallClock($instant));

        // IntlDateFormatter::format() returns false on failure. Treating that as
        // "no date" keeps a malformed pattern from taking a whole table down.
        return $formatted === false ? null : $formatted;
    }

    /**
     * Relative time — "۳ روز پیش".
     *
     * Carbon rather than ICU, because Carbon owns the relative-time phrasing for
     * every locale Laravel supports and ICU's RelativeDateTimeFormatter does not
     * cover the "pick the largest sensible unit" behaviour this is wanted for.
     * Carbon emits ASCII digits regardless of locale, so the result is passed
     * through digits() — this is the one place that is necessary.
     */
    public static function human(
        DateTimeInterface|string|int|null $value,
        ?string $locale = null,
    ): ?string {
        $instant = self::instant($value);

        if ($instant === null) {
            return null;
        }

        $locale = self::locale($locale);

        return self::digits($instant->locale($locale)->diffForHumans(), $locale);
    }

    /**
     * A number in the locale's digits — "۱۲٬۳۴۵".
     *
     * Dashboard stat widgets show counts next to Persian dates; ASCII numerals
     * beside Persian ones look like two different interfaces.
     */
    public static function number(int|float $value, ?string $locale = null): string
    {
        return (string) self::numberFormatter(self::locale($locale), NumberFormatter::DECIMAL)
            ->format($value);
    }

    /**
     * A percentage in the locale's digits and with the locale's own sign — «۴۵٪»
     * for Persian, "45%" for English.
     *
     * Takes a RATIO, not a percentage: ICU multiplies by 100 itself. The sign is
     * ICU's rather than a literal, because «٪» is right for fa/ar and wrong for en
     * — hardcoding it renders "45٪" in an English panel.
     */
    public static function percent(float $ratio, ?string $locale = null): string
    {
        return (string) self::numberFormatter(self::locale($locale), NumberFormatter::PERCENT)
            ->format($ratio);
    }

    /**
     * Re-render the ASCII digits in a string as the locale's digits.
     *
     * For text this class did not format. Anything from IntlDateFormatter or
     * NumberFormatter is already localised.
     */
    public static function digits(string $value, ?string $locale = null): string
    {
        $glyphs = self::digitGlyphs($locale);

        if ($glyphs === self::ASCII_DIGITS) {
            return $value;
        }

        /** @var array<string, string> $map */
        $map = array_combine(
            mb_str_split(self::ASCII_DIGITS),
            mb_str_split($glyphs),
        );

        return strtr($value, $map);
    }

    /**
     * The ten digit glyphs of a locale, as one string.
     *
     * Exposed because the admin's date picker renders its calendar grid in
     * JavaScript and is handed these rather than reimplementing a digit map — see
     * App\Filament\Forms\Components\LocalizedDateTimePicker.
     */
    public static function digitGlyphs(?string $locale = null): string
    {
        return self::DIGITS[self::numbering($locale)] ?? self::ASCII_DIGITS;
    }

    /**
     * The locale a date is being rendered for: the caller's, or the application's.
     *
     * Falling back to app()->getLocale() is what makes the admin panel work with
     * no per-call-site locale argument — RULE #5 runs it at `fa` — while the
     * Delivery API passes the locale the request resolved to.
     */
    public static function locale(?string $locale = null): string
    {
        if ($locale !== null && $locale !== '') {
            return $locale;
        }

        return app()->getLocale();
    }

    /**
     * The ICU calendar for a locale: persian, gregorian, islamic-umalqura, …
     */
    public static function calendar(?string $locale = null): string
    {
        /** @var array<string, string> $calendars */
        $calendars = config('cms.dates.calendars', []);

        return $calendars[self::locale($locale)] ?? 'gregorian';
    }

    /**
     * The ICU numbering system for a locale: arabext, arab, latn, …
     */
    public static function numbering(?string $locale = null): string
    {
        /** @var array<string, string> $numbers */
        $numbers = config('cms.dates.numbers', []);

        return $numbers[self::locale($locale)] ?? 'latn';
    }

    /**
     * Whether a locale renders dates in the Gregorian calendar.
     *
     * Used to decide whether a calendar-specific UI is needed at all; the admin's
     * picker hands Gregorian locales back to Filament's stock component.
     */
    public static function isGregorian(?string $locale = null): bool
    {
        return self::calendar($locale) === 'gregorian';
    }

    /**
     * The ICU locale identifier carrying the calendar and numbering keywords,
     * e.g. `fa@calendar=persian;numbers=arabext`.
     */
    public static function icuLocale(?string $locale = null): string
    {
        $locale = self::locale($locale);

        return sprintf(
            '%s@calendar=%s;numbers=%s',
            $locale,
            self::calendar($locale),
            self::numbering($locale),
        );
    }

    /**
     * Resolve a pattern name from config, or pass a raw ICU pattern through.
     */
    public static function pattern(string $name): string
    {
        /** @var array<string, string> $patterns */
        $patterns = config('cms.dates.patterns', []);

        return $patterns[$name] ?? $name;
    }

    /**
     * The timezone dates are displayed in — NOT config('app.timezone').
     *
     * Falls back to the application timezone rather than throwing, because a
     * mistyped CMS_DISPLAY_TIMEZONE should degrade to UTC dates, not take the
     * whole panel and API down with an unhandled exception on every page.
     */
    public static function timezone(): DateTimeZone
    {
        $configured = config('cms.dates.timezone');

        if (is_string($configured) && $configured !== '') {
            try {
                return new DateTimeZone($configured);
            } catch (Exception) {
                // Fall through to the application timezone.
            }
        }

        return new DateTimeZone((string) config('app.timezone', 'UTC'));
    }

    /**
     * The month names of a locale's calendar, indexed 1..n.
     *
     * Read from ICU rather than a translation file: «مهر» is a property of the
     * Persian calendar, not a string the CMS gets to translate, and a lang file
     * would have to be edited for every additional calendar.
     *
     * @return array<int, string>
     */
    public static function monthNames(?string $locale = null, string $pattern = 'MMMM'): array
    {
        $locale = self::locale($locale);
        $calendar = self::calendarInstance($locale);
        $formatter = self::formatter($locale, $pattern);

        // Anchored to the calendar's own current year, so a month whose name
        // depends on the year (a Hebrew leap month, say) resolves consistently.
        $year = $calendar->get(IntlCalendar::FIELD_YEAR);
        $lastMonth = $calendar->getMaximum(IntlCalendar::FIELD_MONTH);

        $names = [];

        for ($month = 0; $month <= $lastMonth; $month++) {
            $calendar->clear();
            $calendar->set(IntlCalendar::FIELD_YEAR, $year);
            $calendar->set(IntlCalendar::FIELD_MONTH, $month);
            $calendar->set(IntlCalendar::FIELD_DAY_OF_MONTH, 1);

            $name = $formatter->format($calendar);

            if ($name !== false) {
                // 1-indexed: FIELD_MONTH is 0-based and passing that convention
                // out of this class has caused enough off-by-one bugs elsewhere.
                $names[$month + 1] = $name;
            }
        }

        return $names;
    }

    /**
     * Weekday names, ordered from the locale's own first day of the week.
     *
     * Persian weeks begin on Saturday, and a picker whose grid starts on Monday
     * is not merely unidiomatic — an editor reads the wrong column for the wrong
     * day. ICU knows this per locale, so it is asked rather than configured.
     *
     * @return list<string>
     */
    public static function weekdayLabels(?string $locale = null, string $pattern = 'EEEEE'): array
    {
        $locale = self::locale($locale);
        $calendar = self::calendarInstance($locale);
        $formatter = self::formatter($locale, $pattern);

        $first = self::firstDayOfWeek($locale);
        $labels = [];

        for ($offset = 0; $offset < 7; $offset++) {
            // ICU days of the week are 1 = Sunday … 7 = Saturday.
            $day = (($first - 1 + $offset) % 7) + 1;

            $calendar->set(IntlCalendar::FIELD_DAY_OF_WEEK, $day);

            $label = $formatter->format($calendar);

            if ($label !== false) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * The locale's first day of the week, as ICU numbers them: 1 = Sunday … 7 =
     * Saturday. Persian and Arabic locales answer 7.
     */
    public static function firstDayOfWeek(?string $locale = null): int
    {
        return self::calendarInstance(self::locale($locale))->getFirstDayOfWeek();
    }

    /**
     * The last $count months of the locale's calendar, most recent last.
     *
     * Each entry is a half-open interval — `start <= x < end` — of real UTC
     * instants, ready for a whereBetween against a timestamp column, plus a label
     * already rendered in the locale's calendar.
     *
     * The boundaries are the calendar's own, which is the entire point: a
     * "content published per month" chart for a Persian newsroom must break at
     * the 1st of Mehr, not the 1st of September. The two are three weeks apart
     * and a Gregorian bucket silently splits every Persian month in two.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    public static function recentMonths(
        int $count,
        ?string $locale = null,
        string $labelPattern = 'month_short',
    ): array {
        if ($count < 1) {
            return [];
        }

        $locale = self::locale($locale);
        $calendar = self::calendarInstance($locale);

        // Truncate to the first instant of the current calendar month, then walk
        // back. Done with IntlCalendar::add() rather than by subtracting days,
        // because month lengths differ between calendars and within a year.
        $calendar->set(IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $calendar->set(IntlCalendar::FIELD_HOUR_OF_DAY, 0);
        $calendar->set(IntlCalendar::FIELD_MINUTE, 0);
        $calendar->set(IntlCalendar::FIELD_SECOND, 0);
        $calendar->set(IntlCalendar::FIELD_MILLISECOND, 0);
        $calendar->add(IntlCalendar::FIELD_MONTH, -($count - 1));

        $months = [];

        for ($index = 0; $index < $count; $index++) {
            $start = self::fromWallClock($calendar->getTime());

            $calendar->add(IntlCalendar::FIELD_MONTH, 1);

            $months[] = [
                'start' => $start,
                'end' => self::fromWallClock($calendar->getTime()),
                'label' => self::format($start, $labelPattern, $locale) ?? '',
            ];
        }

        return $months;
    }

    /**
     * The locale's calendar, compact enough to hand to a browser.
     *
     * Exists because the admin's date picker runs in JavaScript and JavaScript has
     * no Persian calendar it can be trusted with. The alternative — porting the
     * 33-year-cycle arithmetic into the panel — produces a client that disagrees
     * with ICU about which years are leap, so the grid offers a 30th of Esfand the
     * server then rejects. Shipping ICU's own answer removes the possibility.
     *
     * The shape is deliberately minimal: the day number (whole days since
     * 1970-01-01) that the first year in range begins on, then one character per
     * month for every month in range. Month starts are cumulative sums of that,
     * which the client computes once.
     *
     * Returns null for any calendar this encoding cannot represent — a year with
     * other than 12 months, or a month length outside MONTH_LENGTH_CODES — so the
     * caller can fall back rather than render a wrong calendar.
     *
     * @return array{firstYear: int, monthsPerYear: int, epochDay: int, months: string}|null
     */
    public static function calendarTable(
        int $yearsBefore = 70,
        int $yearsAfter = 30,
        ?string $locale = null,
    ): ?array {
        $calendar = self::calendarInstance(self::locale($locale));

        $monthsPerYear = $calendar->getMaximum(IntlCalendar::FIELD_MONTH) + 1;

        if ($monthsPerYear !== 12) {
            // Hebrew and other calendars with a variable number of months per
            // year cannot be addressed as (year * 12 + month).
            return null;
        }

        $currentYear = $calendar->get(IntlCalendar::FIELD_YEAR);
        $firstYear = $currentYear - $yearsBefore;

        $months = '';
        $epochDay = null;

        for ($year = $firstYear; $year <= $currentYear + $yearsAfter; $year++) {
            for ($month = 0; $month < $monthsPerYear; $month++) {
                $calendar->clear();
                $calendar->set(IntlCalendar::FIELD_YEAR, $year);
                $calendar->set(IntlCalendar::FIELD_MONTH, $month);
                $calendar->set(IntlCalendar::FIELD_DAY_OF_MONTH, 1);

                if ($epochDay === null) {
                    // intdiv() truncates towards zero, which is wrong for the
                    // pre-1970 instants this range starts in: it would put the
                    // whole table one day late.
                    $epochDay = (int) floor($calendar->getTime() / 1000 / 86400);
                }

                $length = $calendar->getActualMaximum(IntlCalendar::FIELD_DAY_OF_MONTH);

                if (! array_key_exists($length, self::MONTH_LENGTH_CODES)) {
                    return null;
                }

                $months .= self::MONTH_LENGTH_CODES[$length];
            }
        }

        if ($epochDay === null) {
            return null;
        }

        return [
            'firstYear' => $firstYear,
            'monthsPerYear' => $monthsPerYear,
            'epochDay' => $epochDay,
            'months' => $months,
        ];
    }

    /**
     * Normalise anything date-shaped to a Carbon instant, or null.
     */
    private static function instant(DateTimeInterface|string|int|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_int($value)) {
            return CarbonImmutable::createFromTimestamp($value, 'UTC');
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            // A malformed string in a database column must not surface as a 500
            // in a table listing.
            return null;
        }
    }

    /**
     * An instant's wall-clock reading in the display timezone, relabelled as UTC.
     *
     * PHP has done the timezone arithmetic by this point; the UTC label tells ICU
     * not to do any of its own with its stale rules. See the class docblock.
     */
    private static function wallClock(DateTimeInterface $instant): DateTimeImmutable
    {
        $local = CarbonImmutable::instance($instant)->setTimezone(self::timezone());

        return new DateTimeImmutable($local->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    }

    /**
     * The inverse of wallClock(): an ICU wall-clock reading, in milliseconds,
     * back to the real instant it denotes.
     */
    private static function fromWallClock(float $milliseconds): CarbonImmutable
    {
        $wall = CarbonImmutable::createFromTimestamp((int) round($milliseconds / 1000), 'UTC');

        return CarbonImmutable::parse($wall->format('Y-m-d H:i:s'), self::timezone())->utc();
    }

    private static function formatter(string $locale, string $pattern): IntlDateFormatter
    {
        $icuLocale = self::icuLocale($locale);
        $key = $icuLocale.'|'.$pattern;

        return self::$formatters[$key] ??= new IntlDateFormatter(
            $icuLocale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            // UTC, always: wallClock() has already applied the display timezone.
            new DateTimeZone('UTC'),
            // TRADITIONAL honours the locale's `calendar` keyword. The default is
            // GREGORIAN, which silently ignores `@calendar=persian` and renders a
            // Gregorian date in Persian words — the exact failure this whole
            // class exists to avoid, and an easy one to miss in review.
            IntlDateFormatter::TRADITIONAL,
            $pattern,
        );
    }

    private static function numberFormatter(string $locale, int $style): NumberFormatter
    {
        $icuLocale = self::icuLocale($locale);
        $key = $icuLocale.'|'.$style;

        return self::$numberFormatters[$key] ??= new NumberFormatter($icuLocale, $style);
    }

    /**
     * A calendar instance set to now, in UTC wall-clock terms.
     */
    private static function calendarInstance(string $locale): IntlCalendar
    {
        $calendar = IntlCalendar::createInstance(
            new DateTimeZone('UTC'),
            self::icuLocale($locale),
        );

        $calendar->setTime(self::wallClock(CarbonImmutable::now())->getTimestamp() * 1000);

        return $calendar;
    }
}
