<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Support\Dates\LocalizedDate;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Js;
use Throwable;

/**
 * A date/time field that picks dates in the panel locale's own calendar.
 *
 * Drop-in replacement for Filament's DateTimePicker — same state, same casts,
 * same validation, same `->seconds()` / `->minDate()` / `->timezone()` API — that
 * swaps the calendar grid for one in the locale's calendar. For Persian that means
 * an editor chooses «۵ مهر ۱۴۰۵» and the database still stores a UTC Gregorian
 * timestamp.
 *
 * ---------------------------------------------------------------------------
 * Why the field had to be replaced rather than configured
 * ---------------------------------------------------------------------------
 * Filament's picker has two modes and neither can show a Persian month:
 *
 *  - Native (the previous setting here) delegates to the browser's own
 *    `datetime-local` control. That is an operating-system widget; no attribute,
 *    stylesheet or translation can change the calendar it draws, so a Persian
 *    panel asked editors to convert dates in their heads.
 *  - Non-native draws Filament's own grid, but it is built on Day.js, which is
 *    Gregorian. Its Persian "locale" translates the month NAMES of Gregorian
 *    months, which is worse than leaving it in English: «سپتامبر ۲۰۲۶» looks
 *    localised and is not the calendar the editor is thinking in.
 *
 * ---------------------------------------------------------------------------
 * What this class does NOT do
 * ---------------------------------------------------------------------------
 * It performs no calendar arithmetic and touches no timezone. Filament's
 * DateTimeStateCast already hands the component a wall-clock reading in the
 * picker's timezone and converts it back on save, so the inherited state
 * machinery is left exactly as it is — that is the reason this is a subclass and
 * not a new field. All it overrides is the markup, plus `isNative()` so the state
 * format matches the markup being rendered.
 *
 * For a Gregorian locale it renders nothing of its own and defers to the parent,
 * so an English or Arabic panel keeps Filament's stock picker rather than an
 * imitation of it.
 */
class LocalizedDateTimePicker extends DateTimePicker
{
    /**
     * Where `filament:assets` publishes the Alpine component.
     *
     * A package name of our own, not `filament`: the published path is
     * public/js/{package}/components/{id}.js, and writing into Filament's
     * directory would put a project file somewhere `filament:assets --force`
     * treats as its own.
     */
    public const ASSET_PACKAGE = 'cms';

    public const ASSET_ID = 'localized-date-time-picker';

    /**
     * Resolved once per component: building it walks ~1,200 ICU calendar fields.
     *
     * @var array{firstYear: int, monthsPerYear: int, epochDay: int, months: string}|null
     */
    protected ?array $calendarTable = null;

    protected bool $hasResolvedCalendarTable = false;

    /**
     * False whenever this component draws its own grid.
     *
     * Not cosmetic: getInternalFormat() returns 'Y-m-d H:i:s' only for a
     * non-native picker, and the Alpine component reads and writes exactly that
     * format. Left at the inherited value, a field declared native would hand the
     * grid a date-only state and silently drop the time on every save.
     */
    public function isNative(): bool
    {
        if ($this->rendersOwnCalendar()) {
            return false;
        }

        return parent::isNative();
    }

    public function toEmbeddedHtml(): string
    {
        $table = $this->calendarTable();

        if ($table === null) {
            // Gregorian locale, or a calendar the table cannot encode. Filament's
            // own picker is correct for that case and better than a copy of it.
            return parent::toEmbeddedHtml();
        }

        $locale = $this->getLocale();
        $statePath = $this->getStatePath();
        $id = $this->getId();
        $livewireKey = $this->getLivewireKey();

        $isDisabled = $this->isDisabled();
        $isReadOnly = $this->isReadOnly();
        $isLocked = $isDisabled || $isReadOnly;
        $placeholder = $this->getPlaceholder();

        $hasDate = $this->hasDate();
        $hasTime = $this->hasTime();
        $hasSeconds = $this->hasSeconds();

        $wrapperAttributes = $this->getExtraAttributeBag()
            ->merge([
                'x-on:focus-input.stop' => "\$el.querySelector('button')?.focus()",
            ], escape: false)
            ->class(['fi-fo-date-time-picker', 'cms-localized-date-time-picker']);

        $alpineConfig = Js::from([
            'calendar' => $table,
            'closeOnDateSelection' => $this->shouldCloseOnDateSelection(),
            'digits' => LocalizedDate::digitGlyphs($locale),
            'firstDayOfWeek' => LocalizedDate::firstDayOfWeek($locale),
            'hasDate' => $hasDate,
            'hasSeconds' => $hasSeconds,
            'hasTime' => $hasTime,
            'isRequired' => $this->isRequired(),
            'maxDate' => $this->wallClockBound($this->getMaxDate()),
            'minDate' => $this->wallClockBound($this->getMinDate()),
            // Values, not translation keys: ICU owns «مهر» and «شنبه», and a lang
            // file would have to be maintained per calendar to say the same thing.
            'monthNames' => array_values(LocalizedDate::monthNames($locale)),
            'today' => $this->todayWallClock(),
            'weekdayLabels' => LocalizedDate::weekdayLabels($locale),
        ]);

        // Only the settings that change the SHAPE of the panel belong in the key.
        // Including the state would re-mount the component on every keystroke and
        // close the panel under the editor.
        $fingerprint = substr(md5(serialize([
            $table['firstYear'], $hasDate, $hasTime, $hasSeconds, $isLocked,
            $this->getMinDate(), $this->getMaxDate(),
        ])), 0, 32);

        ob_start(); ?>

        <div
            x-load
            x-load-src="<?= e(FilamentAsset::getAlpineComponentSrc(self::ASSET_ID, self::ASSET_PACKAGE)) ?>"
            x-data="localizedDateTimePickerFormComponent({
                        ...<?= $alpineConfig ?>,
                        state: $wire.<?= $this->applyStateBindingModifiers("\$entangle('{$statePath}')") ?>,
                    })"
            wire:ignore
            wire:key="<?= e($livewireKey) ?>.<?= $fingerprint ?>"
            x-on:keydown.esc="isPanelOpen() && ($event.stopPropagation(), closePanel())"
            x-on:click.outside="closePanel()"
            <?= $this->getExtraAlpineAttributeBag()->toHtml() ?>
        >
            <button
                x-ref="button"
                type="button"
                x-on:click="togglePanel()"
                <?php if ($isLocked) { ?> disabled <?php } ?>
                aria-label="<?= e((string) ($placeholder ?? $this->getLabel())) ?>"
                class="fi-fo-date-time-picker-trigger"
            >
                <?php /*
                 * readonly, like Filament's own non-native picker: the value is
                 * chosen in the panel. A typable Persian date would need a parser
                 * on both sides and would have to accept digits in two scripts.
                 */ ?>
                <input
                    readonly
                    <?php if ($isDisabled) { ?> disabled <?php } ?>
                    <?php if ($id) { ?> id="<?= e($id) ?>" <?php } ?>
                    placeholder="<?= e($placeholder) ?>"
                    x-bind:value="displayText"
                    wire:key="<?= e($livewireKey) ?>.display-text"
                    class="fi-fo-date-time-picker-display-text-input"
                />
            </button>

            <div
                x-ref="panel"
                x-cloak
                x-float.placement.bottom-start.offset.flip.shift="{ offset: 8 }"
                wire:ignore
                wire:key="<?= e($livewireKey) ?>.panel"
                class="fi-fo-date-time-picker-panel"
            >
                <?php if ($hasDate) { ?>
                    <div class="fi-fo-date-time-picker-panel-header">
                        <?php /*
                         * Previous comes first in the DOM, so an RTL panel puts it
                         * on the right where it belongs, with no direction-aware
                         * markup. Only the chevron glyph needs mirroring, which
                         * the theme does in CSS.
                         */ ?>
                        <button
                            type="button"
                            x-on:click="goToPreviousMonth()"
                            aria-label="<?= e(__('cms.date.previous_month')) ?>"
                            class="cms-calendar-nav"
                        >
                            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" /></svg>
                        </button>

                        <select
                            x-model.number="focusedMonth"
                            aria-label="<?= e(__('filament-forms::components.date_time_picker.month_select.label')) ?>"
                            class="fi-fo-date-time-picker-month-select"
                        >
                            <template x-for="option in monthOptions" x-bind:key="option.value">
                                <option x-bind:value="option.value" x-text="option.label"></option>
                            </template>
                        </select>

                        <?php /*
                         * A select, not Filament's number input: a number input
                         * renders ASCII digits and cannot accept «۱۴۰۵», and the
                         * calendar table only covers a fixed span of years, so a
                         * free-text year would let an editor reach a blank grid.
                         */ ?>
                        <select
                            x-model.number="focusedYear"
                            aria-label="<?= e(__('filament-forms::components.date_time_picker.year_input.label')) ?>"
                            class="fi-fo-date-time-picker-month-select cms-calendar-year-select"
                        >
                            <template x-for="option in yearOptions" x-bind:key="option.value">
                                <option x-bind:value="option.value" x-text="option.label"></option>
                            </template>
                        </select>

                        <button
                            type="button"
                            x-on:click="goToNextMonth()"
                            aria-label="<?= e(__('cms.date.next_month')) ?>"
                            class="cms-calendar-nav"
                        >
                            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                        </button>
                    </div>

                    <div class="fi-fo-date-time-picker-calendar-header">
                        <template x-for="(label, index) in weekdayLabels" x-bind:key="index">
                            <div x-text="label" class="fi-fo-date-time-picker-calendar-header-day"></div>
                        </template>
                    </div>

                    <div role="grid" class="fi-fo-date-time-picker-calendar">
                        <template x-for="blank in leadingBlanks" x-bind:key="'blank-' + blank">
                            <div></div>
                        </template>

                        <template x-for="day in dayCells" x-bind:key="day">
                            <div
                                role="option"
                                x-text="dayLabel(day)"
                                x-on:click="selectDay(day)"
                                x-bind:aria-selected="dayIsSelected(day)"
                                x-bind:class="{
                                    'fi-fo-date-time-picker-calendar-day-today': dayIsToday(day),
                                    'fi-selected': dayIsSelected(day),
                                    'fi-disabled': dayIsDisabled(day) || <?= Js::from($isLocked) ?>,
                                }"
                                class="fi-fo-date-time-picker-calendar-day"
                            ></div>
                        </template>
                    </div>
                <?php } ?>

                <?php if ($hasTime) { ?>
                    <?php /*
                     * The time inputs stay ASCII on purpose. They are editable
                     * numbers, and a field that DISPLAYS «۰۹» has to accept «۰۹»
                     * typed back — which type="number" cannot do. Filament's own
                     * RTL rule already lays the group out correctly.
                     */ ?>
                    <div class="fi-fo-date-time-picker-time-inputs">
                        <input
                            type="number" inputmode="numeric" min="0" max="23"
                            x-model.number="hour"
                            x-on:input.debounce.400ms="commitTime()"
                            x-on:blur="commitTime()"
                            <?php if ($isLocked) { ?> disabled <?php } ?>
                            aria-label="<?= e(__('filament-forms::components.date_time_picker.hour_input.label')) ?>"
                        />
                        <span class="fi-fo-date-time-picker-time-input-separator">:</span>
                        <input
                            type="number" inputmode="numeric" min="0" max="59"
                            x-model.number="minute"
                            x-on:input.debounce.400ms="commitTime()"
                            x-on:blur="commitTime()"
                            <?php if ($isLocked) { ?> disabled <?php } ?>
                            aria-label="<?= e(__('filament-forms::components.date_time_picker.minute_input.label')) ?>"
                        />
                        <?php if ($hasSeconds) { ?>
                            <span class="fi-fo-date-time-picker-time-input-separator">:</span>
                            <input
                                type="number" inputmode="numeric" min="0" max="59"
                                x-model.number="second"
                                x-on:input.debounce.400ms="commitTime()"
                                x-on:blur="commitTime()"
                                <?php if ($isLocked) { ?> disabled <?php } ?>
                                aria-label="<?= e(__('filament-forms::components.date_time_picker.second_input.label')) ?>"
                            />
                        <?php } ?>
                    </div>
                <?php } ?>

                <?php if (! $isLocked) { ?>
                    <div class="cms-calendar-actions">
                        <button type="button" x-on:click="selectToday()" class="cms-calendar-action">
                            <?= e(__('cms.date.today')) ?>
                        </button>

                        <template x-if="canClear">
                            <button type="button" x-on:click="clearState()" class="cms-calendar-action">
                                <?= e(__('cms.date.clear')) ?>
                            </button>
                        </template>
                    </div>
                <?php } ?>
            </div>
        </div>

        <?php $slotHtml = ob_get_clean();

        return $this->wrapEmbeddedHtml(
            $this->wrapInputHtml(
                $slotHtml,
                attributes: $wrapperAttributes,
            ),
            inlineLabelVerticalAlignment: VerticalAlignment::Center,
        );
    }

    /**
     * Whether this component should draw a calendar of its own.
     */
    protected function rendersOwnCalendar(): bool
    {
        return $this->calendarTable() !== null;
    }

    /**
     * @return array{firstYear: int, monthsPerYear: int, epochDay: int, months: string}|null
     */
    protected function calendarTable(): ?array
    {
        if ($this->hasResolvedCalendarTable) {
            return $this->calendarTable;
        }

        $this->hasResolvedCalendarTable = true;

        $locale = $this->getLocale();

        $this->calendarTable = LocalizedDate::isGregorian($locale)
            ? null
            : LocalizedDate::calendarTable(
                (int) config('cms.dates.picker.years_before', 120),
                (int) config('cms.dates.picker.years_after', 30),
                $locale,
            );

        return $this->calendarTable;
    }

    /**
     * "Now", as the wall clock the grid works in.
     *
     * Read from the component's own timezone rather than from LocalizedDate, so a
     * field with an explicit ->timezone() highlights the right day: "today" has to
     * mean the same thing to the grid as it does to the state cast.
     */
    protected function todayWallClock(): string
    {
        return CarbonImmutable::now($this->getTimezone())->format('Y-m-d H:i:s');
    }

    /**
     * A min/max bound normalised to the same wall-clock shape as the state.
     *
     * minDate()/maxDate() accept a Carbon, a relative string ('today', '+1 week')
     * or a formatted date, so they are parsed before being compared.
     *
     * Deliberately NOT converted between timezones. The inherited validation rule
     * is `before_or_equal:{$component->getMaxDate()}`, which Laravel applies to
     * the form state — and the state is already a wall clock in the picker's
     * timezone. Filament therefore treats the bound as a naive wall clock too, and
     * shifting it here would grey out a different set of days from the ones
     * validation rejects, which is the one outcome worse than either behaviour on
     * its own.
     *
     * An unparseable bound is dropped rather than thrown: validation still
     * enforces it, and a grid greying out the wrong days is worse than a grid
     * greying out none.
     */
    protected function wallClockBound(?string $bound): ?string
    {
        if ($bound === null || $bound === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($bound)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
