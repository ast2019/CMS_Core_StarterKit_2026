<x-filament-panels::page>
    {{-- Item 43. Grid from App\Support\Dates\CalendarMonth, entries from App\Support\EditorialCalendar. --}}
    @if ($calendar === null)
        <x-filament::section>
            {{ __('cms.editorial_calendar.unavailable') }}
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">
                {{ $calendar->label() }}
            </x-slot>

            <x-slot name="description">
                {{ __('cms.editorial_calendar.intro', ['timezone' => $timezone]) }}
            </x-slot>

            <x-slot name="afterHeader">
                {{-- "Previous" first in the DOM, so an RTL panel puts it on the right with no
                     direction-aware markup. Labels rather than chevrons for the same reason:
                     an arrow's meaning flips with the reading direction, a word does not. --}}
                <div class="cms-editorial-calendar-nav">
                    <x-filament::button
                        color="gray"
                        size="sm"
                        wire:click="previousMonth"
                        :disabled="! $hasPrevious"
                    >
                        {{ __('cms.editorial_calendar.previous') }}
                    </x-filament::button>

                    <x-filament::button
                        color="gray"
                        size="sm"
                        wire:click="currentMonth"
                        :disabled="$isCurrent"
                    >
                        {{ __('cms.editorial_calendar.today') }}
                    </x-filament::button>

                    <x-filament::button
                        color="gray"
                        size="sm"
                        wire:click="nextMonth"
                        :disabled="! $hasNext"
                    >
                        {{ __('cms.editorial_calendar.next') }}
                    </x-filament::button>
                </div>
            </x-slot>

            <ul class="cms-editorial-calendar-legend">
                @foreach ($states as $state)
                    <li class="cms-editorial-calendar-state cms-editorial-calendar-state-{{ $state['color'] }}">
                        {{ $state['label'] }}
                    </li>
                @endforeach
            </ul>

            <div class="cms-editorial-calendar-scroll">
                <table class="cms-editorial-calendar" wire:key="calendar-{{ $calendar->key() }}">
                    <thead>
                        <tr>
                            @foreach ($weekdays as $weekday)
                                <th scope="col">{{ $weekday }}</th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($weeks as $week)
                            <tr>
                                @foreach ($week as $cell)
                                    @if ($cell === null)
                                        <td class="cms-editorial-calendar-blank"></td>
                                    @else
                                        @php($dayEntries = $entries[$cell['epochDay']] ?? [])

                                        <td
                                            wire:key="day-{{ $cell['epochDay'] }}"
                                            @class([
                                                'cms-editorial-calendar-day',
                                                'cms-editorial-calendar-today' => $cell['today'],
                                            ])
                                        >
                                            <div class="cms-editorial-calendar-day-number">
                                                {{ $cell['label'] }}
                                            </div>

                                            @if ($dayEntries !== [])
                                                <ul x-data="{ expanded: false }" class="cms-editorial-calendar-entries">
                                                    @foreach ($dayEntries as $index => $entry)
                                                        <li
                                                            @if ($index >= $visiblePerDay) x-show="expanded" x-cloak @endif
                                                            class="cms-editorial-calendar-entry cms-editorial-calendar-state-{{ $entry['color'] }}"
                                                            title="{{ $entry['type'] }} · {{ \App\Support\EditorialCalendar::stateLabel($entry['state']) }} · {{ $entry['title'] }}"
                                                        >
                                                            <span class="cms-editorial-calendar-entry-meta">
                                                                {{ $entry['time'] }} · {{ $entry['type'] }}
                                                            </span>

                                                            @if ($entry['url'] !== null)
                                                                <a href="{{ $entry['url'] }}" class="cms-editorial-calendar-entry-title">
                                                                    {{ $entry['title'] }}
                                                                </a>
                                                            @else
                                                                <span class="cms-editorial-calendar-entry-title">
                                                                    {{ $entry['title'] }}
                                                                </span>
                                                            @endif
                                                        </li>
                                                    @endforeach

                                                    @if (count($dayEntries) > $visiblePerDay)
                                                        <li x-show="! expanded">
                                                            <button
                                                                type="button"
                                                                class="cms-editorial-calendar-more"
                                                                x-on:click="expanded = true"
                                                            >
                                                                {{ \App\Support\Plural::choice('cms.editorial_calendar.more', count($dayEntries) - $visiblePerDay) }}
                                                            </button>
                                                        </li>
                                                    @endif
                                                </ul>
                                            @endif
                                        </td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($entries === [])
                <p class="cms-editorial-calendar-empty">
                    {{ __('cms.editorial_calendar.empty') }}
                </p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
