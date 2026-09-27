<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Every `cms.*` key the application asks for exists in all three locales
|--------------------------------------------------------------------------
|
| WHY THIS EXISTS. A missing translation key does not error in Laravel — `__()` returns the
| key itself — so the panel renders `cms.system.status.scheduler` where a label belongs and
| the build stays green. That is the project's least favourite kind of failure, and it has
| two causes that no other test catches:
|
|  1. A key added to lang/fa and forgotten in lang/en or lang/ar. The Persian panel looks
|     finished and the other two are broken for exactly the feature just shipped.
|  2. A DUPLICATE top-level key in a lang file. PHP silently keeps only the last array entry
|     with a given key, so adding a second `'system' => [...]` block discards the first
|     one's contents entirely — every key inside it, in all three files at once, with no
|     warning from the parser, from Pint or from PHPStan. This test was written after exactly
|     that happened.
|
| Scope: literal keys only. A key assembled at runtime — `"cms.menu.location.{$key}"`,
| `'cms.usage.label.'.$type` — cannot be resolved statically, and both of those call sites
| deliberately fall back to a raw value when no translation exists, which is a documented
| behaviour rather than a defect. Interpolated and concatenated keys are therefore skipped;
| what is left is the large majority, and it is the part where a typo is invisible.
|
*/

use Illuminate\Support\Facades\Lang;

/**
 * Literal `cms.*` translation keys referenced anywhere in the application.
 *
 * @return list<string>
 */
function referencedCmsTranslationKeys(): array
{
    /*
     * Wider than the two directories that hold call sites today. `routes/`, `config/`,
     * `database/` and `bootstrap/` contain none, so this costs nothing now — but a validation
     * message added to a route file or a seeder would otherwise be outside the check, and the
     * whole point is that a missing key leaves no other trace.
     */
    $files = [
        ...collectFiles(projectPath('app'), ['php']),
        ...collectFiles(projectPath('resources/views'), ['php']),
        ...collectFiles(projectPath('routes'), ['php']),
        ...collectFiles(projectPath('database'), ['php']),
        ...collectFiles(projectPath('config'), ['php']),
        ...collectFiles(projectPath('bootstrap'), ['php']),
    ];

    $keys = [];

    foreach ($files as $file) {
        // Comments are stripped for the reason Pest.php explains: several files in this
        // project quote keys in prose while explaining a decision about them.
        $source = stripComments($file);

        /*
         * Matched as the first argument of a TRANSLATION call, not as a bare `'cms.…'`
         * literal. `config('cms.api.delivery.cache_ttl')` shares the prefix — the config
         * file and the lang files are both namespaced `cms` — so a looser pattern demands a
         * translation for every configuration key in the project.
         *
         * Single-quoted only. A double-quoted key may carry interpolation, and
         * "cms.menu.location.{$key}" is a real, correct call site with a documented fallback;
         * matching it would fail this test on code working as designed.
         */
        preg_match_all(
            "/(?:__|trans|trans_choice|Lang::get|Lang::has|Lang::choice)\\(\\s*'(cms\\.[a-z0-9_.]+)'/i",
            $source,
            $matches,
        );

        foreach ($matches[1] as $key) {
            // A trailing dot means the literal was the prefix half of a concatenation.
            if (! str_ends_with($key, '.')) {
                $keys[$key] = true;
            }
        }
    }

    return array_keys($keys);
}

it('resolves every literal cms translation key in fa, en and ar', function (): void {
    $referenced = referencedCmsTranslationKeys();

    // A guard on the SCAN, not on the translations. If a refactor moved the call sites or
    // broke the pattern, this test would otherwise pass by finding nothing to check.
    expect($referenced)->not->toBeEmpty();

    $missing = [];

    foreach (['fa', 'en', 'ar'] as $locale) {
        foreach ($referenced as $key) {
            /*
             * `Lang::has` with the exact locale and no fallback. Asking `__()` instead would
             * quietly answer with the fallback locale's string, which is precisely the
             * situation this test exists to find: an Arabic panel showing Persian.
             */
            if (! Lang::has($key, $locale, fallback: false)) {
                $missing[] = "{$locale}: {$key}";
            }
        }
    }

    expect($missing)->toBe([]);
});

/**
 * Every string key declared twice inside the same array level of a PHP array literal.
 *
 * Tokenised rather than matched with a regex. The first version of this check anchored on
 * four-space indentation, which made it top-level-only — so it could not see a duplicated
 * NESTED group, and a duplicated nested group is exactly the shape these files have grown
 * (`'status'` inside `'system'`). It also only looked for keys whose value was an array, so a
 * repeated scalar such as `'changelog_path'` was exempt for no reason.
 *
 * Walking tokens instead removes all three limitations and does not care about formatting.
 *
 * @return list<string>
 */
function duplicateLangKeyPaths(string $path): array
{
    $tokens = token_get_all((string) file_get_contents($path));

    /** @var list<array<string, int>> $seenPerLevel */
    $seenPerLevel = [[]];

    /** @var list<string> $pathParts */
    $pathParts = [];

    $duplicates = [];
    $pendingKey = null;

    foreach ($tokens as $index => $token) {
        // An array level opens on `[` and closes on `]`. Casting to string covers both the
        // single-character tokens (plain strings) and the typed ones.
        $text = is_array($token) ? $token[1] : $token;

        if (! is_array($token) && ($text === '[' || $text === '(')) {
            $seenPerLevel[] = [];
            $pathParts[] = $pendingKey ?? '?';
            $pendingKey = null;

            continue;
        }

        if (! is_array($token) && ($text === ']' || $text === ')')) {
            array_pop($seenPerLevel);
            array_pop($pathParts);

            continue;
        }

        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        /*
         * A string is a KEY only when the next significant token is `=>`. Without that check
         * every translated sentence would be treated as a key and the test would report
         * nonsense for any two identical values.
         */
        $next = null;

        for ($ahead = $index + 1; $ahead < count($tokens); $ahead++) {
            $candidate = $tokens[$ahead];

            if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $next = $candidate;
            break;
        }

        if (! (is_array($next) && $next[0] === T_DOUBLE_ARROW)) {
            continue;
        }

        $key = trim($text, "'\"");
        $depth = count($seenPerLevel) - 1;

        if (isset($seenPerLevel[$depth][$key])) {
            $prefix = implode('.', array_slice($pathParts, 1));
            $duplicates[] = ($prefix === '' ? '' : $prefix.'.').$key;
        }

        $seenPerLevel[$depth][$key] = 1;
        $pendingKey = $key;
    }

    return $duplicates;
}

it('declares no key twice at any level of any lang file', function (): void {
    /*
     * The specific accident this guards. PHP keeps the LAST entry for a repeated array key, so
     * a second `'system' => [...]` silently deletes the first — every key inside it, with no
     * warning from the parser, from Pint or from PHPStan. The keys test above catches it only
     * if something happens to reference the lost keys; this one catches it immediately and
     * says what is wrong rather than listing symptoms.
     *
     * EVERY lang file, not just cms.php: a duplicate in validation.php would break error
     * messages just as quietly.
     */
    $files = collectFiles(projectPath('lang'), ['php']);

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(duplicateLangKeyPaths($file))->toBe([], "duplicate key(s) in {$file}");
    }
});
