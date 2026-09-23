<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

/**
 * Persian sample text for factories.
 *
 * Why this exists: Faker's `fa_IR` locale ships Person, Address, Company,
 * Internet and PhoneNumber providers but NO Lorem provider. So `sentence()`,
 * `word()` and `paragraph()` silently fall back to en_US Latin lorem ipsum even
 * when APP_FAKER_LOCALE=fa_IR. Seeding a Persian RTL CMS with Latin text is not
 * a cosmetic problem: LTR strings lay out correctly in an RTL container by
 * accident, so bidirectional bugs — misplaced punctuation, reversed
 * parentheses, truncation on the wrong edge — stay invisible until a real
 * editor types real Persian.
 *
 * `realText()` IS available for fa_IR (via its Text provider) and returns
 * Markov-chain prose from a Persian corpus. The output is semantically
 * nonsensical but orthographically genuine, which is exactly what layout and
 * search-indexing tests need.
 */
trait GeneratesPersianText
{
    /**
     * Curated nouns for taxonomy names, where realText fragments would read as
     * gibberish in a navigation menu.
     *
     * @var list<string>
     */
    private const TOPIC_WORDS = [
        'اخبار', 'فناوری', 'اقتصاد', 'ورزش', 'فرهنگ', 'هنر', 'سیاست', 'سلامت',
        'آموزش', 'گردشگری', 'محیط زیست', 'خودرو', 'موسیقی', 'سینما', 'کتاب',
        'پژوهش', 'صنعت', 'کشاورزی', 'انرژی', 'حمل و نقل', 'مسکن', 'بازار',
        'تحلیل', 'گزارش', 'مصاحبه', 'یادداشت', 'ویدیو', 'زیرساخت', 'نوآوری',
    ];

    /**
     * A headline-length Persian string with no trailing punctuation.
     */
    protected function persianTitle(int $maxChars = 70): string
    {
        return $this->trimSentence($this->persianText($maxChars));
    }

    protected function persianSentence(int $maxChars = 140): string
    {
        return $this->persianText($maxChars);
    }

    protected function persianParagraph(int $maxChars = 420): string
    {
        return $this->persianText($maxChars);
    }

    /**
     * A distinct Persian topic word, optionally combined for more variety than
     * the source list alone allows.
     */
    protected function persianTopic(bool $allowCompound = false): string
    {
        $word = $this->faker->randomElement(self::TOPIC_WORDS);

        if ($allowCompound && $this->faker->boolean(30)) {
            $second = $this->faker->randomElement(self::TOPIC_WORDS);

            if ($second !== $word) {
                return $word.' '.$second;
            }
        }

        return $word;
    }

    /**
     * realText() throws below 10 characters and needs a corpus large enough to
     * satisfy the request, so the bound is clamped rather than trusted.
     */
    private function persianText(int $maxChars): string
    {
        $maxChars = max(12, min($maxChars, 900));

        return $this->faker->realText($maxChars);
    }

    /**
     * Strip the trailing sentence punctuation realText leaves behind, since a
     * headline ending in a full stop looks like a bug to an editor.
     *
     * MUST NOT use rtrim() with this character list. rtrim() treats its charlist
     * as a set of single BYTES, and Persian punctuation (، ؛ ؟) plus ZWNJ are
     * multi-byte in UTF-8 — so rtrim strips fragments of whatever multi-byte
     * character happens to end the string, producing invalid UTF-8.
     *
     * The consequence was not a visible mojibake but a silent data loss:
     * json_encode() returns false on invalid UTF-8, spatie/laravel-translatable
     * stores that false, and it reached the database as the integer 0. The title
     * became 0, so no slug could be generated from it, and the insert failed on a
     * NOT NULL constraint several steps away from the real cause.
     *
     * preg_replace with the /u modifier is character-aware, so it cannot split a
     * codepoint.
     */
    private function trimSentence(string $text): string
    {
        $trimmed = preg_replace(
            '/[\s.،؛:!؟\x{200C}\-]+$/u',
            '',
            trim($text),
        );

        // preg_replace returns null on error (for instance if the subject is
        // already invalid UTF-8, which /u rejects). Falling back to the input
        // keeps a bad string visible rather than turning it into an empty title.
        return $trimmed ?? trim($text);
    }
}
