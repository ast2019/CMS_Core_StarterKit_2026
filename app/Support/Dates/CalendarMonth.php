<?php

declare(strict_types=1);

namespace App\Support\Dates;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Item 43 — one month of the panel's calendar, laid out as a grid of weeks.
 *
 * Everything calendar-specific comes from LocalizedDate::calendarTable(), the same table the date
 * picker is handed: the day each month begins on and how long it is. This class only adds sums and
 * a modulo-7, so there is no Jalali (or Hijri, or Gregorian) arithmetic here to disagree with ICU —
 * the 30th of Esfand 1403 exists in this grid because ICU says it does, not because a leap-year
 * rule was copied correctly.
 *
 * A DAY is a whole calendar day in the display timezone, identified by its "epoch day": the number
 * of days from 1970-01-01 to that wall-clock date. That is the unit calendarTable() is expressed in,
 * and it turns "which cell does this article go in" into integer comparison instead of a question
 * about timezones asked once per record.
 */
final class CalendarMonth
{
    /**
     * Resolved tables, one per locale and reach. The table is anchored to the current year, so each
     * entry remembers the day it was built for and is REPLACED (not added to) when that changes —
     * a long-running worker crossing Nowruz rebuilds it without keeping every earlier day's copy.
     *
     * @var array<string, array{day: int, table: array{firstYear: int, starts: list<int>, lengths: list<int>}|null}>
     */
    private static array $tables = [];

    /**
     * @param  list<int>  $starts  First epoch day of every month in range, in order.
     * @param  list<int>  $lengths  Length of every month in range, in order.
     */
    private function __construct(
        public readonly int $year,
        public readonly int $month,
        private readonly int $index,
        private readonly int $firstYear,
        private readonly array $starts,
        private readonly array $lengths,
        private readonly string $locale,
    ) {}

    /**
     * The month containing today, in the locale's calendar.
     *
     * Null when the locale's calendar cannot be tabulated (see LocalizedDate::calendarTable()).
     */
    public static function current(?string $locale = null): ?self
    {
        $locale = LocalizedDate::locale($locale);
        $table = self::table($locale);

        if ($table === null) {
            return null;
        }

        $index = self::indexContaining($table['starts'], $table['lengths'], self::epochDayOf(CarbonImmutable::now()));

        return $index === null ? null : self::at($index, $table, $locale);
    }

    /**
     * A given month of the locale's calendar; $month is 1-based.
     *
     * Null for a month outside the reachable range or one that does not exist, so a hand-edited
     * URL falls back to the current month instead of rendering a grid that means nothing.
     */
    public static function of(int $year, int $month, ?string $locale = null): ?self
    {
        $locale = LocalizedDate::locale($locale);
        $table = self::table($locale);

        if ($table === null || $month < 1 || $month > 12) {
            return null;
        }

        $index = ($year - $table['firstYear']) * 12 + ($month - 1);

        if ($index < 0 || $index >= count($table['starts'])) {
            return null;
        }

        return self::at($index, $table, $locale);
    }

    /**
     * The wall-clock day an instant falls on in the display timezone, as an epoch day.
     *
     * The timezone shift is PHP's, for the reason LocalizedDate gives: ICU's tzdata is older and
     * still believes Iran observes summer time.
     */
    public static function epochDayOf(DateTimeInterface $instant): int
    {
        $date = CarbonImmutable::instance($instant)
            ->setTimezone(LocalizedDate::timezone())
            ->format('Y-m-d');

        return (int) floor(CarbonImmutable::parse($date, 'UTC')->getTimestamp() / 86400);
    }

    /**
     * The real instant a day begins at: midnight of that wall-clock date in the display timezone.
     */
    public static function startOfEpochDay(int $epochDay): CarbonImmutable
    {
        $date = gmdate('Y-m-d', $epochDay * 86400);

        return CarbonImmutable::parse($date, LocalizedDate::timezone())->utc();
    }

    public function previous(): ?self
    {
        return $this->sibling($this->index - 1);
    }

    public function next(): ?self
    {
        return $this->sibling($this->index + 1);
    }

    public function length(): int
    {
        return $this->lengths[$this->index];
    }

    public function firstEpochDay(): int
    {
        return $this->starts[$this->index];
    }

    /**
     * First instant of the month, UTC. The interval is half-open: start <= x < end.
     */
    public function start(): CarbonImmutable
    {
        return self::startOfEpochDay($this->firstEpochDay());
    }

    /**
     * First instant of the FOLLOWING month, UTC.
     */
    public function end(): CarbonImmutable
    {
        return self::startOfEpochDay($this->firstEpochDay() + $this->length());
    }

    /**
     * "مهر ۱۴۰۵". The month name is ICU's, the year is the locale's digits WITHOUT grouping — a
     * number formatter would print «۱٬۴۰۵», which is a quantity, not a year.
     */
    public function label(): string
    {
        $name = LocalizedDate::monthNames($this->locale)[$this->month] ?? (string) $this->month;

        return $name.' '.LocalizedDate::digits((string) $this->year, $this->locale);
    }

    /**
     * A stable identifier for URLs, e.g. "1405-07". ASCII digits whatever the locale.
     */
    public function key(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    /**
     * The month as weeks of seven cells, starting on the locale's own first day of the week.
     *
     * Cells before the 1st and after the last day are null, so the view draws an empty square
     * rather than borrowing days from the neighbouring months — which would put two "5"s in one
     * grid and invite an editor to schedule into the wrong one.
     *
     * @return list<list<array{day: int, epochDay: int, label: string, today: bool}|null>>
     */
    public function weeks(): array
    {
        $first = $this->firstEpochDay();
        $today = self::epochDayOf(CarbonImmutable::now());

        $leading = (self::weekdayOf($first) - LocalizedDate::firstDayOfWeek($this->locale) + 7) % 7;

        $cells = array_fill(0, $leading, null);

        for ($day = 1; $day <= $this->length(); $day++) {
            $epochDay = $first + $day - 1;

            $cells[] = [
                'day' => $day,
                'epochDay' => $epochDay,
                'label' => LocalizedDate::digits((string) $day, $this->locale),
                'today' => $epochDay === $today,
            ];
        }

        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }

        return array_chunk($cells, 7);
    }

    /**
     * ICU's day of the week for an epoch day: 1 = Sunday … 7 = Saturday.
     *
     * 1970-01-01 was a Thursday (5). The double modulo keeps pre-1970 days — which the table
     * reaches — from producing a negative remainder.
     */
    public static function weekdayOf(int $epochDay): int
    {
        return ((($epochDay + 4) % 7) + 7) % 7 + 1;
    }

    private function sibling(int $index): ?self
    {
        if ($index < 0 || $index >= count($this->starts)) {
            return null;
        }

        return self::at($index, [
            'firstYear' => $this->firstYear,
            'starts' => $this->starts,
            'lengths' => $this->lengths,
        ], $this->locale);
    }

    /**
     * @param  array{firstYear: int, starts: list<int>, lengths: list<int>}  $table
     */
    private static function at(int $index, array $table, string $locale): self
    {
        return new self(
            year: $table['firstYear'] + intdiv($index, 12),
            month: $index % 12 + 1,
            index: $index,
            firstYear: $table['firstYear'],
            starts: $table['starts'],
            lengths: $table['lengths'],
            locale: $locale,
        );
    }

    /**
     * @param  list<int>  $starts
     * @param  list<int>  $lengths
     */
    private static function indexContaining(array $starts, array $lengths, int $epochDay): ?int
    {
        foreach ($starts as $index => $start) {
            if ($epochDay >= $start && $epochDay < $start + $lengths[$index]) {
                return $index;
            }
        }

        return null;
    }

    /**
     * calendarTable() decoded into month starts and lengths.
     *
     * The reach is the date picker's (`cms.dates.picker`), so the calendar can show every month an
     * editor can pick a date in, and no month they cannot.
     *
     * @return array{firstYear: int, starts: list<int>, lengths: list<int>}|null
     */
    private static function table(string $locale): ?array
    {
        $before = (int) config('cms.dates.picker.years_before', 120);
        $after = (int) config('cms.dates.picker.years_after', 30);

        $key = implode('|', [LocalizedDate::icuLocale($locale), $before, $after]);
        $today = self::epochDayOf(CarbonImmutable::now());

        if (isset(self::$tables[$key]) && self::$tables[$key]['day'] === $today) {
            return self::$tables[$key]['table'];
        }

        $table = self::decode(LocalizedDate::calendarTable($before, $after, $locale));

        self::$tables[$key] = ['day' => $today, 'table' => $table];

        return $table;
    }

    /**
     * @param  array{firstYear: int, monthsPerYear: int, epochDay: int, months: string}|null  $raw
     * @return array{firstYear: int, starts: list<int>, lengths: list<int>}|null
     */
    private static function decode(?array $raw): ?array
    {
        /*
         * An era-relative calendar (japanese) tabulates, but its years restart with each era, so the
         * range reaches "year −112" — which key() cannot write and nobody can read. Such a calendar
         * gets the "unavailable" message rather than a grid of meaningless labels.
         */
        if ($raw === null || $raw['monthsPerYear'] !== 12 || $raw['firstYear'] < 1) {
            return null;
        }

        // The encoder's own map, inverted, so the two cannot drift apart.
        $decode = array_flip(LocalizedDate::MONTH_LENGTH_CODES);
        $starts = [];
        $lengths = [];
        $cursor = $raw['epochDay'];

        foreach (str_split($raw['months']) as $code) {
            $length = $decode[$code] ?? null;

            if ($length === null) {
                return null;
            }

            $starts[] = $cursor;
            $lengths[] = $length;
            $cursor += $length;
        }

        return [
            'firstYear' => $raw['firstYear'],
            'starts' => $starts,
            'lengths' => $lengths,
        ];
    }
}
