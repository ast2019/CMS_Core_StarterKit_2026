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

    /*
     * The activity log stores a JSON snapshot, so a timestamp arrives here as a
     * plain string — there is no Carbon instance and no cast to hang a formatter
     * off. Without this, the one screen in the panel that still showed raw
     * «2026-09-26 12:00:00» was the audit diff, which is also the screen where an
     * editor is most likely to be comparing a date against one they read
     * elsewhere in the panel.
     *
     * Two conditions must both hold before a value is reinterpreted as a date:
     * the attribute is named like one, AND the value is an ISO datetime for its
     * whole length. Either test alone is too loose — `layout_at_a_glance` is not a
     * timestamp, and a meta description may legitimately begin with a date — and
     * silently rewriting a content value in an audit trail would be the worst
     * possible place to be wrong.
     */
    $isDateAttribute = fn (string $key): bool => str_ends_with($key, '_at')
        || str_ends_with($key, '_date');

    $asDate = function (mixed $value) {
        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value) !== 1) {
            return null;
        }

        return \App\Support\Dates\LocalizedDate::format($value, 'date_time_seconds');
    };

    $render = function (mixed $value, string $key) use ($isDateAttribute, $asDate): string {
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

        if ($isDateAttribute($key) && ($localised = $asDate($value)) !== null) {
            return $localised;
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
                        <td class="align-top"><pre class="whitespace-pre-wrap">{{ $render($before, $key) }}</pre></td>
                        <td class="align-top"><pre class="whitespace-pre-wrap">{{ $render($after, $key) }}</pre></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
