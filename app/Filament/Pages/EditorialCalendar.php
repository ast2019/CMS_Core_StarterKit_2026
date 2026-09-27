<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PanelNavigationGroup;
use App\Support\Dates\CalendarMonth;
use App\Support\Dates\LocalizedDate;
use App\Support\EditorialCalendar as Calendar;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Item 43 — the editorial calendar: what goes out when, across articles, pages and galleries.
 *
 * The grid is the panel's calendar, so a Persian newsroom plans in Mehr and Aban with weeks that
 * begin on Saturday. Month boundaries and lengths come from CalendarMonth, which reads ICU's table;
 * the entries come from App\Support\EditorialCalendar. This class only holds which month is showing.
 *
 * No calendar plugin: a month grid is a few dozen lines of Blade, and a plugin would bring its own
 * Gregorian arithmetic that would have to be defeated for every non-Gregorian locale.
 */
class EditorialCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 14;

    protected string $view = 'filament.pages.editorial-calendar';

    /**
     * The month showing, as "yyyy-mm" in the panel's calendar ("1405-07").
     *
     * In the URL so a month can be linked and survives a reload. A string rather than two integers
     * because it is user input: anything unparseable or out of range falls back to the current
     * month in resolveMonth() instead of failing a typed-property cast.
     */
    #[Url(as: 'month')]
    public ?string $month = null;

    public static function getNavigationLabel(): string
    {
        return __('cms.editorial_calendar.title');
    }

    public function getTitle(): string
    {
        return __('cms.editorial_calendar.title');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return PanelNavigationGroup::Content;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('content.view') ?? false;
    }

    public function previousMonth(): void
    {
        $this->month = $this->resolveMonth()?->previous()?->key() ?? $this->month;
    }

    public function nextMonth(): void
    {
        $this->month = $this->resolveMonth()?->next()?->key() ?? $this->month;
    }

    public function currentMonth(): void
    {
        $this->month = null;
    }

    public function resolveMonth(): ?CalendarMonth
    {
        if ($this->month !== null && preg_match('/^(\d{1,4})-(\d{1,2})$/', $this->month, $matches) === 1) {
            $requested = CalendarMonth::of((int) $matches[1], (int) $matches[2]);

            if ($requested !== null) {
                return $requested;
            }
        }

        return CalendarMonth::current();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $month = $this->resolveMonth();

        // Not `month`: Livewire hands public properties to the view too, and the URL string
        // would shadow the object.
        if ($month === null) {
            return ['calendar' => null];
        }

        $current = CalendarMonth::current();

        return [
            'calendar' => $month,
            'weeks' => $month->weeks(),
            'weekdays' => LocalizedDate::weekdayLabels(null, 'EEEE'),
            'entries' => Calendar::entriesFor($month),
            'hasPrevious' => $month->previous() !== null,
            'hasNext' => $month->next() !== null,
            'isCurrent' => $current !== null && $current->key() === $month->key(),
            'states' => array_map(fn (string $state): array => [
                'label' => Calendar::stateLabel($state),
                'color' => Calendar::colorOf($state),
            ], Calendar::states()),
            'timezone' => LocalizedDate::timezone()->getName(),
            // Cells show this many entries before folding the rest behind "N more".
            'visiblePerDay' => 3,
        ];
    }
}
