{{-- Requirement 3.7 — restorable editorial snapshots. --}}
@php
    $locale = app()->getLocale();
@endphp

<div class="cms-version-history">
    @if ($versions->isEmpty())
        <p>{{ __('cms.version.empty') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr>
                    <th class="text-start">#</th>
                    <th class="text-start">{{ __('cms.audit.when') }}</th>
                    <th class="text-start">{{ __('cms.audit.who') }}</th>
                    <th class="text-start">{{ __('cms.field.title') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($versions as $version)
                    @php
                        // HasContentVersions normalises translatable values to
                        // locale maps, so this is always an array keyed by locale.
                        $rawTitle = $version->payload['title'] ?? $version->payload['name'] ?? null;

                        $title = is_array($rawTitle) && $rawTitle !== []
                            ? ($rawTitle[$locale] ?? reset($rawTitle))
                            : '—';
                    @endphp

                    <tr>
                        <td>{{ $version->version_number }}</td>
                        <td>{{ $version->created_at?->format('Y-m-d H:i') }}</td>
                        <td>{{ $version->author?->name ?? __('cms.audit.system') }}</td>
                        <td>{{ $title }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p class="mt-4 text-sm">{{ __('cms.version.keep_notice', ['count' => config('cms.versions.keep')]) }}</p>
    @endif
</div>
