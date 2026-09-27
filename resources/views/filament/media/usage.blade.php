{{-- Item 12. Rows from App\Filament\Resources\MediaAssets\MediaUsageList. --}}
@if ($usage !== null)
    <div class="cms-media-usage">
        @if ($usage['total'] === 0 && $usage['logo'] === null)
            <p class="cms-media-usage-none">{{ __('cms.media.usage.none') }}</p>
        @else
            <p class="cms-media-usage-summary">
                {{ \App\Support\Plural::choice('cms.media.usage.summary', $usage['total'] + ($usage['logo'] !== null ? 1 : 0)) }}
            </p>

            <ul class="cms-media-usage-list">
                @if ($usage['logo'] !== null)
                    <li class="cms-media-usage-item">
                        <span class="cms-media-usage-type">{{ __('cms.media.usage.logo') }}</span>

                        @if ($usage['logo']['url'] !== null)
                            <a href="{{ $usage['logo']['url'] }}" class="cms-media-usage-title">{{ __('cms.settings.title') }}</a>
                        @else
                            <span class="cms-media-usage-title">{{ __('cms.settings.title') }}</span>
                        @endif
                    </li>
                @endif

                @foreach ($usage['items'] as $item)
                    <li class="cms-media-usage-item">
                        <span class="cms-media-usage-type">{{ $item['type'] }} · {{ $item['role'] }}</span>

                        @if ($item['url'] !== null)
                            <a href="{{ $item['url'] }}" class="cms-media-usage-title">{{ $item['title'] }}</a>
                        @else
                            <span class="cms-media-usage-title">{{ $item['title'] }}</span>
                        @endif

                        @if ($item['trashed'])
                            <x-filament::badge color="gray" size="sm">{{ __('cms.media.usage.trashed') }}</x-filament::badge>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($usage['hidden'] > 0)
                <p class="cms-media-usage-more">
                    {{ \App\Support\Plural::choice('cms.media.usage.more', $usage['hidden']) }}
                </p>
            @endif
        @endif
    </div>
@endif
