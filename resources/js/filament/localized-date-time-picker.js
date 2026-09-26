/**
 * Date picker for a calendar that is not Gregorian — in practice, Persian.
 *
 * Paired with App\Filament\Forms\Components\LocalizedDateTimePicker, which renders
 * the markup and supplies everything locale-specific. Loaded on demand by
 * async-alpine through Filament's x-load / x-load-src mechanism, so it costs
 * nothing on a page with no date field.
 *
 * ---------------------------------------------------------------------------
 * Why this file contains no calendar arithmetic
 * ---------------------------------------------------------------------------
 * Converting Persian dates to Gregorian in JavaScript normally means porting the
 * 33-year-cycle algorithm, which disagrees with the astronomical calendar ICU uses
 * on leap years — so the panel would render one date and the server would store
 * another, in a handful of years nobody tests. Two implementations of a calendar
 * is one too many.
 *
 * Instead PHP asks ICU for a table and passes it in: the day number of the first
 * day of the first year in range, then one character per month giving that month's
 * length. Everything below is addition over that table, so the browser and the
 * server cannot disagree by construction. A hundred years of months is about a
 * kilobyte.
 *
 * ---------------------------------------------------------------------------
 * Why there is no Date arithmetic either
 * ---------------------------------------------------------------------------
 * The state Filament hands over is already a WALL CLOCK in the panel's display
 * timezone — DateTimeStateCast converted it on the way in and converts it back on
 * the way out. So this component must not apply an offset of its own, and any use
 * of the browser's local timezone would do exactly that, shifting every date by
 * the difference between the editor's machine and the site's timezone.
 *
 * Dates are therefore handled as a "day number" — whole days since 1970-01-01 —
 * with the clock kept separately as plain integers. `Date` appears only via
 * `Date.UTC`, purely as a Gregorian calendar routine with no timezone involved.
 */

const MS_PER_DAY = 86400000

/**
 * Month lengths are encoded one character each, because a hundred years of them
 * as JSON integers is several kilobytes of payload for three distinct values.
 */
const MONTH_LENGTHS = { 8: 28, 9: 29, 0: 30, 1: 31 }

const pad = (value, length = 2) => String(value).padStart(length, '0')

/** Whole days since the epoch for a Gregorian Y-M-D. No timezone applied. */
const dayNumberFromGregorian = (year, month, day) =>
    Math.floor(Date.UTC(year, month - 1, day) / MS_PER_DAY)

/** The inverse. */
const gregorianFromDayNumber = (dayNumber) => {
    const date = new Date(dayNumber * MS_PER_DAY)

    return {
        year: date.getUTCFullYear(),
        month: date.getUTCMonth() + 1,
        day: date.getUTCDate(),
    }
}

/**
 * ICU numbers the days of the week 1 = Sunday … 7 = Saturday, and PHP sends
 * firstDayOfWeek and the weekday labels in that scheme. Day number 0 —
 * 1970-01-01 — was a Thursday, which is ICU's 5.
 */
const weekdayFromDayNumber = (dayNumber) => (((dayNumber + 4) % 7) + 7) % 7 + 1

/**
 * The calendar table, turned into lookups.
 *
 * `starts` holds the day number each month in the table begins on, so converting
 * in either direction is an array read or a binary search rather than a
 * calculation.
 */
function buildCalendar({ firstYear, monthsPerYear, epochDay, months }) {
    const monthCount = months.length
    const starts = new Array(monthCount + 1)

    starts[0] = epochDay

    for (let index = 0; index < monthCount; index++) {
        starts[index + 1] = starts[index] + (MONTH_LENGTHS[months[index]] ?? 30)
    }

    const indexOf = (year, month) =>
        (year - firstYear) * monthsPerYear + (month - 1)

    return {
        firstYear,
        lastYear: firstYear + monthCount / monthsPerYear - 1,
        monthsPerYear,

        covers(year) {
            return year >= this.firstYear && year <= this.lastYear
        },

        daysInMonth(year, month) {
            return MONTH_LENGTHS[months[indexOf(year, month)]] ?? 30
        },

        toDayNumber(year, month, day) {
            return starts[indexOf(year, month)] + day - 1
        },

        /** null for a day outside the table, which the caller must handle. */
        fromDayNumber(dayNumber) {
            if (dayNumber < starts[0] || dayNumber >= starts[monthCount]) {
                return null
            }

            let low = 0
            let high = monthCount - 1

            while (low < high) {
                const middle = (low + high + 1) >> 1

                if (starts[middle] <= dayNumber) {
                    low = middle
                } else {
                    high = middle - 1
                }
            }

            return {
                year: firstYear + Math.floor(low / monthsPerYear),
                month: (low % monthsPerYear) + 1,
                day: dayNumber - starts[low] + 1,
            }
        },
    }
}

/**
 * Split a 'Y-m-d H:i:s' wall clock into a day number and a clock.
 *
 * The time part is optional: a date-only picker still receives Filament's
 * internal format, but a value that has been cleared arrives as null.
 */
function parseWallClock(value) {
    if (!value) {
        return null
    }

    const parts = String(value).match(
        /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/,
    )

    if (parts === null) {
        return null
    }

    return {
        dayNumber: dayNumberFromGregorian(+parts[1], +parts[2], +parts[3]),
        hour: +(parts[4] ?? 0),
        minute: +(parts[5] ?? 0),
        second: +(parts[6] ?? 0),
    }
}

const formatWallClock = ({ dayNumber, hour, minute, second }) => {
    const { year, month, day } = gregorianFromDayNumber(dayNumber)

    return (
        `${pad(year, 4)}-${pad(month)}-${pad(day)}` +
        ` ${pad(hour)}:${pad(minute)}:${pad(second)}`
    )
}

export default function localizedDateTimePickerFormComponent({
    calendar,
    closeOnDateSelection,
    digits,
    firstDayOfWeek,
    hasDate,
    hasSeconds,
    hasTime,
    isRequired,
    maxDate,
    minDate,
    monthNames,
    state,
    today,
    weekdayLabels,
}) {
    return {
        state,

        calendar: buildCalendar(calendar),

        /** The month the grid is showing, in the target calendar. */
        focusedYear: null,
        focusedMonth: null,

        hour: 0,
        minute: 0,
        second: 0,

        init() {
            this.syncFromState()

            // Filament writes the state back after validation and after a
            // dependent field reloads the schema; without this the panel keeps
            // showing the value the editor replaced.
            this.$watch('state', () => this.syncFromState())
        },

        /* ----------------------------------------------------------------- *
         * Reading the state
         * ----------------------------------------------------------------- */

        syncFromState() {
            const parsed = parseWallClock(this.state)

            this.hour = parsed?.hour ?? 0
            this.minute = parsed?.minute ?? 0
            this.second = parsed?.second ?? 0

            /*
             * The table covers a finite span of years, so a stored date can fall
             * outside it — content imported from an older archive, or a date set
             * through the Management API. Anchor the grid on today in that case.
             *
             * Without this the focus stayed null and the grid rendered NaN cells
             * whose day numbers serialised to "0NaN-NaN-NaN", which Filament's
             * state cast then threw on. An unreachable date must degrade to a
             * usable picker, never to a corrupted value.
             */
            const focused =
                (parsed === null
                    ? null
                    : this.calendar.fromDayNumber(parsed.dayNumber)) ??
                this.calendar.fromDayNumber(parseWallClock(today)?.dayNumber ?? 0)

            if (focused !== null) {
                this.focusedYear = focused.year
                this.focusedMonth = focused.month
            }
        },

        get selected() {
            const parsed = parseWallClock(this.state)

            return parsed === null
                ? null
                : this.calendar.fromDayNumber(parsed.dayNumber)
        },

        get todayParts() {
            const parsed = parseWallClock(today)

            return parsed === null
                ? null
                : this.calendar.fromDayNumber(parsed.dayNumber)
        },

        /* ----------------------------------------------------------------- *
         * What the closed field shows
         * ----------------------------------------------------------------- */

        /**
         * Deliberately the NUMERIC form — «۱۴۰۵/۰۷/۰۵ ۰۹:۳۰» — even though the
         * panel's tables show the long form («۵ مهر ۱۴۰۵»). A field the editor
         * reads back while choosing a date wants fixed-width, unambiguous digits
         * in a predictable position; a month name makes two adjacent dates hard
         * to compare at a glance.
         */
        get displayText() {
            const parsed = parseWallClock(this.state)

            if (parsed === null) {
                return ''
            }

            const parts = this.calendar.fromDayNumber(parsed.dayNumber)

            if (parts === null) {
                /*
                 * A real date the table cannot name. Showing it as Gregorian is
                 * honest and lets the editor see what is stored; showing nothing
                 * would read as "this field is empty" and invite them to
                 * overwrite a value they never saw.
                 */
                return this.localiseDigits(this.state)
            }

            let text = hasDate
                ? `${pad(parts.year, 4)}/${pad(parts.month)}/${pad(parts.day)}`
                : ''

            if (hasTime) {
                const clock =
                    `${pad(parsed.hour)}:${pad(parsed.minute)}` +
                    (hasSeconds ? `:${pad(parsed.second)}` : '')

                text = text === '' ? clock : `${text} ${clock}`
            }

            return this.localiseDigits(text)
        },

        /**
         * ASCII digits to the locale's, using the glyphs PHP read out of ICU.
         * Keeping the map on the PHP side is what lets `ar` render Arabic-Indic
         * digits without a second branch here.
         */
        localiseDigits(value) {
            if (digits === '0123456789') {
                return value
            }

            return String(value).replace(/\d/g, (digit) => digits[+digit])
        },

        /* ----------------------------------------------------------------- *
         * The grid
         * ----------------------------------------------------------------- */

        get monthOptions() {
            return monthNames.map((label, index) => ({
                value: index + 1,
                label,
            }))
        },

        /**
         * A select rather than a free-text year input.
         *
         * A number input shows ASCII digits and cannot be typed into in Persian,
         * and the table only covers a fixed span of years anyway — so offering a
         * box that accepts 1200 and then renders an empty grid would be worse
         * than not offering it.
         */
        get yearOptions() {
            const years = []

            for (
                let year = this.calendar.firstYear;
                year <= this.calendar.lastYear;
                year++
            ) {
                years.push({ value: year, label: this.localiseDigits(year) })
            }

            return years
        },

        get weekdayLabels() {
            return weekdayLabels
        },

        /** True once the grid has a month it can actually draw. */
        get hasFocusedMonth() {
            return (
                this.focusedYear !== null &&
                this.focusedMonth !== null &&
                this.calendar.covers(this.focusedYear)
            )
        },

        get daysInFocusedMonth() {
            // 0 rather than NaN: x-for over NaN renders nothing useful and the
            // day cells would carry NaN labels.
            return this.hasFocusedMonth
                ? this.calendar.daysInMonth(this.focusedYear, this.focusedMonth)
                : 0
        },

        /**
         * Blank cells before the 1st, so the columns line up under the weekday
         * headings. The headings start at the locale's own first day of the week —
         * Saturday for Persian — so the offset is measured from there.
         */
        get leadingBlanks() {
            if (!this.hasFocusedMonth) {
                return 0
            }

            const first = this.calendar.toDayNumber(
                this.focusedYear,
                this.focusedMonth,
                1,
            )

            return (weekdayFromDayNumber(first) - firstDayOfWeek + 7) % 7
        },

        get dayCells() {
            return Array.from(
                { length: this.daysInFocusedMonth },
                (_, index) => index + 1,
            )
        },

        dayLabel(day) {
            return this.localiseDigits(day)
        },

        dayIsToday(day) {
            const now = this.todayParts

            return (
                now !== null &&
                now.year === this.focusedYear &&
                now.month === this.focusedMonth &&
                now.day === day
            )
        },

        dayIsSelected(day) {
            const selected = this.selected

            return (
                selected !== null &&
                selected.year === this.focusedYear &&
                selected.month === this.focusedMonth &&
                selected.day === day
            )
        },

        dayIsDisabled(day) {
            const dayNumber = this.calendar.toDayNumber(
                this.focusedYear,
                this.focusedMonth,
                day,
            )

            const min = parseWallClock(minDate)
            const max = parseWallClock(maxDate)

            if (min !== null && dayNumber < min.dayNumber) {
                return true
            }

            return max !== null && dayNumber > max.dayNumber
        },

        /* ----------------------------------------------------------------- *
         * Navigation and selection
         * ----------------------------------------------------------------- */

        goToPreviousMonth() {
            this.shiftMonth(-1)
        },

        goToNextMonth() {
            this.shiftMonth(1)
        },

        shiftMonth(delta) {
            let month = this.focusedMonth + delta
            let year = this.focusedYear

            if (month < 1) {
                month = this.calendar.monthsPerYear
                year -= 1
            } else if (month > this.calendar.monthsPerYear) {
                month = 1
                year += 1
            }

            if (!this.calendar.covers(year)) {
                return
            }

            this.focusedYear = year
            this.focusedMonth = month
        },

        selectDay(day) {
            // No focused month means the grid never resolved against the table,
            // so there is no date this click could mean. Writing one anyway is
            // what produced NaN state.
            if (this.focusedYear === null || this.focusedMonth === null) {
                return
            }

            if (this.dayIsDisabled(day)) {
                return
            }

            this.state = formatWallClock({
                dayNumber: this.calendar.toDayNumber(
                    this.focusedYear,
                    this.focusedMonth,
                    day,
                ),
                hour: hasTime ? this.hour : 0,
                minute: hasTime ? this.minute : 0,
                second: hasTime && hasSeconds ? this.second : 0,
            })

            if (closeOnDateSelection) {
                this.closePanel()
            }
        },

        /**
         * Changing the clock must not conjure a date out of nothing: with no day
         * chosen there is no instant to attach an hour to, and writing today's
         * date because the editor touched the minutes field is how a draft
         * acquires a publish date nobody asked for.
         */
        commitTime() {
            this.hour = this.clamp(this.hour, 0, 23)
            this.minute = this.clamp(this.minute, 0, 59)
            this.second = this.clamp(this.second, 0, 59)

            const parsed = parseWallClock(this.state)

            if (parsed === null) {
                return
            }

            this.state = formatWallClock({
                dayNumber: parsed.dayNumber,
                hour: this.hour,
                minute: this.minute,
                second: hasSeconds ? this.second : 0,
            })
        },

        clamp(value, min, max) {
            const number = Number.parseInt(value, 10)

            if (Number.isNaN(number)) {
                return min
            }

            return Math.min(Math.max(number, min), max)
        },

        selectToday() {
            const parsed = parseWallClock(today)

            if (parsed === null) {
                return
            }

            const parts = this.calendar.fromDayNumber(parsed.dayNumber)

            if (parts === null) {
                return
            }

            this.focusedYear = parts.year
            this.focusedMonth = parts.month

            this.hour = parsed.hour
            this.minute = parsed.minute
            this.second = parsed.second

            this.selectDay(parts.day)
        },

        /**
         * Offered only on an optional field. On a required one the button would
         * produce a state the form immediately rejects, which reads as a bug.
         */
        get canClear() {
            return !isRequired
        },

        clearState() {
            if (!this.canClear) {
                return
            }

            this.state = null
            this.closePanel()
        },

        /* ----------------------------------------------------------------- *
         * Panel visibility
         *
         * Delegated to Filament's floating-UI integration rather than a boolean
         * of our own: `x-float` owns the positioning, the flip/shift behaviour
         * near a viewport edge, and the display toggle, and a second source of
         * truth for "is it open" would drift from the one that actually decides
         * whether the panel is on screen.
         * ----------------------------------------------------------------- */

        /**
         * Optional chaining all the way down, including on `$refs` itself.
         *
         * Alpine always provides `$refs`, but the panel element is inside a
         * `wire:ignore` subtree that Livewire can replace, and clearState() is
         * reachable from a keyboard handler that may fire during that window. A
         * throw there would abort the whole Alpine expression and leave the field
         * unresponsive until a reload.
         */
        isPanelOpen() {
            return this.$refs?.panel?.style.display === 'block'
        },

        togglePanel() {
            if (!this.isPanelOpen()) {
                // Re-anchor the grid on the current value each time it opens, so
                // reopening after a change does not show the month the editor
                // navigated away from.
                this.syncFromState()
            }

            this.$refs?.panel?.toggle(this.$refs.button)
        },

        closePanel() {
            if (this.isPanelOpen()) {
                this.$refs.panel.toggle(this.$refs.button)
            }
        },
    }
}
