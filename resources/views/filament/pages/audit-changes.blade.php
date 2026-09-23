{{--
    Before/after diff for one audit entry (Requirement 9.5).

    Keys are unioned across old and new so an attribute that only appears on one
    side — a column added by a migration, or one cleared to null — is still shown.
    Iterating only the new state would hide deletions, which are exactly what an
    audit reader is looking for.
--}}
@php
    $keys = collect(array_keys($old))
        ->merge(array_keys($new))
        ->unique()
        ->reject(fn (string $key): bool => in_array($key, \App\Support\AuditRedaction::ALWAYS_EXCLUDED, true))
        ->sort()
        ->values();

    $render = function (mixed $value): string {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            // Translatable attributes and TipTap documents arrive as arrays.
            // JSON_UNESCAPED_UNICODE keeps Persian readable rather than emitting
            // \u escapes an editor cannot check.
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return (string) $value;
    };
@endphp

<div class="cms-audit-diff">
    @if ($keys->isEmpty())
        <p>{{ __('cms.audit.no_changes') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr>
                    <th class="text-start">{{ __('cms.audit.attribute') }}</th>
                    <th class="text-start">{{ __('cms.audit.before') }}</th>
                    <th class="text-start">{{ __('cms.audit.after') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($keys as $key)
                    @php
                        $before = $old[$key] ?? null;
                        $after = $new[$key] ?? null;
                        $changed = $before !== $after;
                    @endphp

                    <tr @class(['cms-audit-diff__row', 'cms-audit-diff__row--changed' => $changed])>
                        <td class="cms-ltr align-top">{{ $key }}</td>
                        <td class="align-top"><pre class="whitespace-pre-wrap">{{ $render($before) }}</pre></td>
                        <td class="align-top"><pre class="whitespace-pre-wrap">{{ $render($after) }}</pre></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
