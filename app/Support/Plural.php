<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Dates\LocalizedDate;

/**
 * Item 56 — a counted message, with the right noun form AND the reader's own digits.
 *
 * Every count in the panel used to be `__('…', ['count' => $n])` against a single string, so
 * English read "1 record(s)" and Arabic used one noun form for every number — when Arabic has six,
 * each with its own noun and often its own verb agreement. Laravel selects them CLDR-style by the
 * number modulo 100: 0, 1, 2, then n%100 in 3–10, then n%100 in 11–99, then everything else — so
 * 103 takes the "few" form, 111 the "many" form, and 100, 101 and 102 the last one. Form five is
 * "the rest", not "a hundred or more". Persian takes a singular noun after any numeral, so its strings
 * are a single form and are unaffected.
 *
 * Why a helper rather than calling trans_choice() directly:
 *
 *  - trans_choice() fills `:count` with the raw number when no replacement is given, so every call
 *    site would print ASCII "3" beside Persian text unless it also remembered to pass the localised
 *    digits. One missed call site and the panel shows two numbering systems in one sentence.
 *  - The PLURAL FORM is chosen from the raw integer while the DISPLAYED number is localised. Passing
 *    the localised string as the number would break selection: "۳" is not numeric to PHP.
 *  - A key rewritten as "one|many" and still called through __() renders the pipe and both forms
 *    verbatim. tests/Architecture/PluralFormsTest.php guards that, and it can only guard it if there
 *    is one obvious function to call.
 */
class Plural
{
    /**
     * The message for `$count`, in the active locale.
     *
     * @param  array<string, mixed>  $replace
     */
    public static function choice(string $key, int $count, array $replace = []): string
    {
        return trans_choice($key, $count, [
            ...$replace,
            'count' => LocalizedDate::number($count),
        ]);
    }

    /**
     * A key-plus-parameters pair as UsageInspector returns it, choosing the plural when the
     * parameters carry an integer count and falling back to a plain translation when they do not.
     *
     * The shape exists so the inspector can hand back an answer without deciding how it is worded,
     * and several call sites render it; routing all of them through here is what keeps a pluralised
     * key from ever reaching __() with its pipe intact.
     *
     * @param  array<string, int|string>  $parameters
     */
    public static function trans(string $key, array $parameters = []): string
    {
        $count = $parameters['count'] ?? null;

        // Numeric strings too: a count that arrives as "3" must still choose a form, or a pluralised
        // key would fall through to __() and print its pipes.
        if (is_int($count) || (is_string($count) && ctype_digit($count))) {
            unset($parameters['count']);

            return self::choice($key, (int) $count, $parameters);
        }

        $line = __($key, $parameters);

        return is_string($line) ? $line : $key;
    }
}
