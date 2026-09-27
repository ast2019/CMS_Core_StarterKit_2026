<?php

declare(strict_types=1);

use App\Support\Plural;

/*
|--------------------------------------------------------------------------
| Item 56 — counted messages use the right noun form, and cannot print their own pipes
|--------------------------------------------------------------------------
|
| A pluralised string is several forms joined by `|`. Rendered through trans_choice() Laravel picks
| one; rendered through __() it prints ALL of them, pipes included — "Published one record.|Published
| 3 records." in a toast. That failure is silent (no exception, no missing key) and it is exactly what
| happens when a string is pluralised and one call site is forgotten, which is why it is guarded here
| rather than trusted to review.
|
*/

/**
 * Dotted keys of every pluralised string in a lang file.
 *
 * @return array<string, string> key => value
 */
function pluralisedLangStrings(string $locale): array
{
    /** @var array<string, mixed> $lines */
    $lines = require projectPath("lang/{$locale}/cms.php");

    $found = [];

    $walk = function (array $node, string $prefix) use (&$walk, &$found): void {
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $walk($value, $path);
            } elseif (is_string($value) && str_contains($value, '|')) {
                $found["cms.{$path}"] = $value;
            }
        }
    };

    $walk($lines, '');

    return $found;
}

it('gives every English plural two forms and every Arabic plural six', function (): void {
    /*
     * Laravel indexes English as singular|plural and Arabic as zero|one|two|few|many|other, chosen by
     * n % 100 (0, 1, 2, then 3–10, then 11–99, then the rest — 100–102 included, and 103 is "few"). A string with the wrong number of forms does not fail — MessageSelector
     * falls back to the FIRST form for any index it cannot find — so an Arabic string with two forms
     * would show its singular for every count from two upward.
     */
    foreach (['en' => 2, 'ar' => 6] as $locale => $expected) {
        foreach (pluralisedLangStrings($locale) as $key => $value) {
            expect(count(explode('|', $value)))
                ->toBe($expected, "{$locale}: {$key} should have {$expected} forms");
        }
    }
});

it('never renders a pluralised string through __()', function (): void {
    $pluralised = array_keys(pluralisedLangStrings('en'));

    expect($pluralised)->not->toBeEmpty();

    $offenders = [];

    foreach ([...collectFiles(projectPath('app'), ['php']), ...collectFiles(projectPath('resources/views'), ['php'])] as $file) {
        $source = stripComments($file);

        foreach ($pluralised as $key) {
            if (preg_match("/(?:__|trans|Lang::get)\\(\\s*'".preg_quote($key, '/')."'/", $source) === 1) {
                $offenders[] = str_replace(projectPath().'/', '', $file).": {$key}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('picks the Arabic dual rather than the plural for two', function (): void {
    app()->setLocale('ar');

    // Two users is مستخدمَين, not :count مستخدمين — the dual has no numeral at all.
    expect(Plural::choice('cms.action.activate_selected_done', 2))->toBe('تم تنشيط مستخدمَين.')
        // 3–10 take a plural noun; 11–99 take a singular accusative; 100+ a singular genitive.
        ->and(Plural::choice('cms.action.activate_selected_done', 5))->toContain('مستخدمين')
        ->and(Plural::choice('cms.action.activate_selected_done', 15))->toContain('مستخدمًا')
        ->and(Plural::choice('cms.action.activate_selected_done', 200))->toContain('مستخدم.');
});

it('says one record in English rather than 1 record(s)', function (): void {
    app()->setLocale('en');

    expect(Plural::choice('cms.action.publish_selected_done', 1))->toBe('Published one record.')
        ->and(Plural::choice('cms.action.publish_selected_done', 3))->toBe('Published 3 records.');
});

it('shows the count in the reader digits while choosing the form from the number', function (): void {
    /*
     * The two halves have to come from different values. The FORM is chosen from the integer; the
     * DISPLAYED number is localised. Passing «۳» as the number would break selection, since it is not
     * numeric to PHP, and passing 3 as the display value would put ASCII beside Persian text.
     */
    app()->setLocale('fa');

    expect(Plural::choice('cms.action.publish_selected_done', 3))->toContain('۳')
        ->and(Plural::choice('cms.action.publish_selected_done', 3))->not->toContain('3');
});

it('renders a key-and-parameters answer without its pipes', function (): void {
    // The UsageInspector shape, as the delete actions and the model guards render it.
    app()->setLocale('en');

    $message = Plural::trans('cms.usage.blocked.category_primary', ['count' => 1]);

    expect($message)->not->toContain('|')
        ->and($message)->toContain('one article');
});
