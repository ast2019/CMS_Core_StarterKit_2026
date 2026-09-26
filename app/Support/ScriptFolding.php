<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Folds Persian/Arabic text into a shape two strings can be COMPARED in.
 *
 * Used by the SEO keyphrase analysis (App\Services\Seo\SeoAnalyser) to decide
 * whether a focus keyphrase appears in a title, a heading or a paragraph.
 *
 * ---------------------------------------------------------------------------
 * Why this is not App\Services\Content\SlugGenerator
 * ---------------------------------------------------------------------------
 * SlugGenerator already folds Arabic codepoints to Persian ones, and it was the
 * first place looked at. It cannot be reused here, and the reason is worth
 * recording because the two look interchangeable:
 *
 *  - SlugGenerator normalises text that will be STORED and DISPLAYED as a URL, so
 *    its own docblock sets the rule "fold only codepoints Persian orthography
 *    never produces" — it deliberately keeps آ, ئ and ؤ, because folding them
 *    would misspell the word an editor has to proofread in the address bar.
 *  - This folds text that is thrown away immediately after a comparison. Nothing
 *    folded here is ever shown to anyone, so leniency costs nothing and strictness
 *    costs real matches: an editor who types «ازمایش» into the keyphrase field and
 *    «آزمایش» into the title has written the same phrase, and telling them the
 *    keyphrase is missing from their own title would train them to ignore the
 *    whole panel.
 *  - SlugGenerator also splits its maps per locale on purpose (folding ك to ک
 *    would misspell Arabic). That split is right for a stored slug and wrong for a
 *    comparison: the two sides of a keyphrase match may have been typed on
 *    different keyboards in the SAME locale, which is the entire problem.
 *
 * So this is the deliberately more aggressive, locale-independent, union-of-both
 * fold. It is not a normaliser for storage and must never be used as one.
 *
 * What is folded, and why each one earns its place:
 *  - Arabic yeh/kaf/teh-marbuta and every hamza-bearing alef, to their bare
 *    Persian equivalents. These are pure input-method differences.
 *  - Harakat (diacritics) and tatweel, which carry pronunciation or decoration
 *    rather than identity and are absent from the same word typed elsewhere.
 *  - ZWNJ (U+200C) to a space: it is invisible, so «نشانه‌گذاری» and «نشانه گذاری»
 *    look identical to the editor and differ only in one zero-width codepoint.
 *  - Arabic-Indic and Persian digits to ASCII, so «۱۴۰۴» matches «1404».
 *  - Case, for the Latin locale.
 *  - All whitespace runs to a single space, and the result is padded with spaces
 *    by wordBoundedContains() so a phrase can be matched on word boundaries.
 */
final class ScriptFolding
{
    /**
     * Letter-level folding. Union of SlugGenerator's Persian and Arabic maps plus
     * آ, which SlugGenerator keeps for spelling and this drops for matching.
     *
     * @var array<string, string>
     */
    private const LETTERS = [
        'ي' => 'ی',   // Arabic yeh
        'ى' => 'ی',   // alef maksura
        'ك' => 'ک',   // Arabic kaf
        'ة' => 'ه',   // teh marbuta
        'ۀ' => 'ه',   // heh with yeh above
        'أ' => 'ا',
        'إ' => 'ا',
        'آ' => 'ا',
        'ٱ' => 'ا',
        'ٲ' => 'ا',
        'ٳ' => 'ا',
        'ـ' => '',    // tatweel: decorative elongation
    ];

    /**
     * @var array<string, string>
     */
    private const DIGITS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /**
     * Harakat and the Quranic annotation ranges. Same pattern SlugGenerator uses,
     * for the same reason.
     */
    private const DIACRITICS_PATTERN = '/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u';

    /**
     * Punctuation that separates words rather than belonging to one, in both
     * scripts. Folded to a space so «تهران، ایران» and «تهران ایران» compare equal
     * and a keyphrase is not defeated by a comma.
     */
    private const SEPARATORS_PATTERN = '/[\x{060C}\x{061B}\x{061F}\x{2000}-\x{206F}!-\/:-@\[-`{-~]/u';

    private function __construct() {}

    /**
     * The comparable form of a string: folded, lowercased, single-spaced, trimmed.
     */
    public static function fold(string $value): string
    {
        // ZWNJ first: it is a word separator here, and the separator pass below
        // would otherwise not see it (U+200C is inside the punctuation range this
        // class folds, so ordering is what keeps the two rules independent).
        $value = str_replace("\u{200C}", ' ', $value);

        $value = strtr($value, self::LETTERS);
        $value = strtr($value, self::DIGITS);

        $value = (string) preg_replace(self::DIACRITICS_PATTERN, '', $value);
        $value = (string) preg_replace(self::SEPARATORS_PATTERN, ' ', $value);

        $value = mb_strtolower($value, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Whether $needle occurs in $haystack, both folded.
     *
     * A plain substring test, NOT a word-boundary test. Persian agglutinates
     * prefixes and suffixes onto a word («خبرها», «به‌روزرسانی‌ها»), so requiring a
     * boundary would miss the ordinary inflected use of the very phrase the editor
     * is targeting. The cost is a phrase matching inside a longer word, which for
     * a multi-word keyphrase is vanishingly unlikely and, when it happens, errs
     * towards telling the editor their keyphrase IS present.
     */
    public static function contains(string $haystack, string $needle): bool
    {
        $needle = self::fold($needle);

        if ($needle === '') {
            return false;
        }

        return str_contains(self::fold($haystack), $needle);
    }

    /**
     * How many times $needle occurs in $haystack, both folded.
     *
     * Non-overlapping, which is what mb_substr_count gives and what a density
     * figure wants.
     */
    public static function occurrences(string $haystack, string $needle): int
    {
        $needle = self::fold($needle);

        if ($needle === '') {
            return 0;
        }

        $haystack = self::fold($haystack);

        if ($haystack === '') {
            return 0;
        }

        return mb_substr_count($haystack, $needle);
    }

    /**
     * Word count of a folded string.
     *
     * Whitespace-delimited, which is the only definition available without a
     * Persian tokeniser. It UNDERCOUNTS compounds joined by ZWNJ — except that
     * fold() has already turned ZWNJ into a space, so «به‌روزرسانی» counts as two.
     * That is the closer answer for a density denominator: ZWNJ marks a word break
     * inside a compound, which is why SlugGenerator treats it as one too.
     */
    public static function wordCount(string $value): int
    {
        $folded = self::fold($value);

        if ($folded === '') {
            return 0;
        }

        return count(explode(' ', $folded));
    }
}
