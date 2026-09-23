<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Str;

/**
 * Locale-aware slug generation.
 *
 * Why not Str::slug()? Laravel transliterates to ASCII, so the Persian title
 * «عنوان خبر فارسی» becomes "aanoan-khbr-farsy" — unreadable to a Persian
 * speaker, meaningless to a search engine, and impossible for an editor to
 * proofread. Persian sites use UTF-8 slugs, which browsers percent-encode on
 * the wire and display as Persian in the address bar.
 *
 * So for RTL locales this preserves the script and only normalises it. For
 * Latin locales it defers to Str::slug, which is correct there.
 *
 * Requirement 7.1 (editable per-locale slug), Decision D-1.
 */
class SlugGenerator
{
    /**
     * Persian normalisation: Arabic codepoints folded to their Persian
     * equivalents.
     *
     * Persian and Arabic keyboards emit different codepoints for visually
     * near-identical letters. Without folding, «کتابي» typed on an Arabic
     * keyboard and «کتابی» typed on a Persian one are different strings, so they
     * would both pass a uniqueness check and then compete for the same URL in
     * every search index.
     *
     * @var array<string, string>
     */
    private const NORMALISATIONS_FA = [
        'ي' => 'ی',   // Arabic yeh -> Persian yeh
        'ك' => 'ک',   // Arabic kaf -> Persian keheh
        'ة' => 'ه',   // teh marbuta: not used in Persian
        'أ' => 'ا',   // hamza forms: Arabic orthography, Persian writes bare alef
        'إ' => 'ا',
        'ٱ' => 'ا',
        'ۀ' => 'ه',   // heh with yeh above -> heh (خانۀ -> خانه), standard in Persian
    ];

    /*
     * Deliberately NOT folded for Persian:
     *
     *   آ  alef with madda is a distinct Persian letter, not a variant of ا.
     *      Folding it turns «آزمایشی» into «ازمایشی», which is misspelled.
     *   ئ  Persian uses it natively («مسئول»); folding to ی gives «مسیول».
     *   ؤ  Persian uses it natively («مؤسسه»).
     *
     * The rule applied here: fold only codepoints that Persian orthography never
     * produces, so normalisation collapses input-method differences without ever
     * changing the spelling of a Persian word.
     */

    /**
     * Arabic normalisation.
     *
     * Deliberately NOT the Persian map. Folding ك to ک and ة to ه would impose
     * Persian orthography on Arabic text — «الشركة» would become «الشرکه»,
     * which is misspelled to an Arabic reader and would not match how anyone
     * searches for it. Only the alef variants (which genuinely vary by writer)
     * and tatweel (a purely decorative elongation) are folded.
     *
     * @var array<string, string>
     */
    private const NORMALISATIONS_AR = [
        'أ' => 'ا',
        'إ' => 'ا',
        'آ' => 'ا',
        'ٱ' => 'ا',
        'ـ' => '',    // tatweel: decorative stretching, carries no meaning
    ];

    /**
     * Arabic-Indic and extended Arabic-Indic digits mapped to ASCII.
     *
     * Persian digits in a URL are legal but hostile to copy-paste, analytics
     * grouping, and anyone reading a log file, so numbers are always ASCII.
     *
     * @var array<string, string>
     */
    private const DIGIT_NORMALISATIONS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /**
     * Diacritics (harakat) carry pronunciation, not identity, and are usually
     * absent from the same word typed elsewhere. Stripping them keeps slugs
     * stable.
     */
    private const DIACRITICS_PATTERN = '/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u';

    public function generate(string $value, string $locale): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return $this->isRtl($locale)
            ? $this->generateUnicodeSlug($value, $locale)
            : Str::slug($value);
    }

    /**
     * Whether a generated slug is safe to place in a URL path segment.
     *
     * Used by validation so an editor pasting a full URL or a string of
     * punctuation into the slug field gets told why it was rejected.
     */
    public function isValid(string $slug): bool
    {
        if ($slug === '' || mb_strlen($slug) > 255) {
            return false;
        }

        // Reserved path characters, and anything that would change the meaning
        // of the URL rather than sit inside a single segment.
        return preg_match('#[/\\\\?\#\[\]@!$&\'()*+,;=:%\s]#u', $slug) !== 1;
    }

    private function generateUnicodeSlug(string $value, string $locale): string
    {
        $value = mb_strtolower($value, 'UTF-8');

        $value = strtr($value, $this->normalisationsFor($locale));
        $value = strtr($value, self::DIGIT_NORMALISATIONS);
        $value = (string) preg_replace(self::DIACRITICS_PATTERN, '', $value);

        // ZWNJ (U+200C) separates words inside a single Persian compound. It is
        // invisible, so leaving it in produces two slugs that look identical and
        // are not. Treat it as a word break.
        $value = str_replace("\u{200C}", '-', $value);

        // Keep Persian/Arabic letters, ASCII alphanumerics, and separators.
        // Everything else — punctuation, emoji, quotation marks — goes.
        $value = (string) preg_replace(
            '/[^\x{0621}-\x{063A}\x{0641}-\x{064A}\x{066E}-\x{06D5}a-z0-9\s\-_]/u',
            '',
            $value,
        );

        $value = (string) preg_replace('/[\s_]+/u', '-', $value);
        $value = (string) preg_replace('/-+/u', '-', $value);

        return trim($value, '-');
    }

    /**
     * @return array<string, string>
     */
    private function normalisationsFor(string $locale): array
    {
        return $locale === 'ar'
            ? self::NORMALISATIONS_AR
            : self::NORMALISATIONS_FA;
    }

    private function isRtl(string $locale): bool
    {
        return in_array($locale, (array) config('cms.locales.rtl', ['fa', 'ar']), true);
    }
}
