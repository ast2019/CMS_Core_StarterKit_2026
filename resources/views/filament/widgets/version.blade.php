{{-- RULE #2. Requirements 10.1, 10.2. --}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            {{ __('cms.system.about') }}
        </x-slot>

        <x-slot name="description">
            {{ __('cms.system.version') }}:
            {{-- LTR: a semantic version is a Latin/numeric token and reverses
                 visually inside an RTL paragraph. --}}
            <span class="cms-ltr font-mono">{{ $this->getVersion() }}</span>

            @if ($this->getInstalledAt())
                — {{ __('cms.system.installed_at') }}:
                {{-- No .cms-ltr here, unlike the version number above. A Persian
                     date is Persian text in Persian digits; forcing it LTR would
                     pull it out of the sentence it belongs to. --}}
                <span>{{ $this->getInstalledAt() }}</span>
            @endif
        </x-slot>

        @php $releases = $this->getRecentChangelogs(); @endphp

        @if ($releases->isEmpty())
            <p class="text-sm">{{ __('cms.system.no_changelog') }}</p>
        @else
            <div class="space-y-4">
                <p class="text-sm font-medium">{{ __('cms.system.recent_changes') }}</p>

                @foreach ($releases as $release)
                    <div class="text-sm">
                        <p>
                            {{-- The SemVer stays LTR/monospace: it is a Latin token
                                 and reverses visually inside an RTL line. --}}
                            <span class="cms-ltr font-mono font-semibold">{{ $release->version }}</span>
                            <span>{{ $this->getReleaseDate($release) }}</span>
                        </p>

                        <ul class="mt-1 list-inside list-disc">
                            @foreach ($release->entries as $category => $items)
                                @foreach ($items as $item)
                                    <li>
                                        <span class="font-medium">{{ ucfirst($category) }}:</span>
                                        {{ $item }}
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
