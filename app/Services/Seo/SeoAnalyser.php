<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Services\Content\SlugGenerator;
use App\Support\ScriptFolding;
use App\Support\TipTap;

/**
 * Focus-keyphrase and content checks for one record in one locale.
 *
 * Requirement 7.1. The panel renders this (App\Filament\Schemas\SeoSection) and the
 * model exposes it (HasSeoMeta::seoAnalysisFor()), so the score an editor sees and
 * the score the API reports are computed once, in here.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DOES NOT MEASURE, stated plainly
 * ---------------------------------------------------------------------------
 * This is a Persian-first CMS, and every well-known SEO scoring plugin gets its
 * apparent intelligence from language resources this project does not have and
 * cannot honestly fake:
 *
 *  - No stemming or lemmatisation. Persian and Arabic are morphologically rich:
 *    «کتاب», «کتابها», «کتابهای», «کتابخانه» are one keyphrase family to a reader
 *    and four different strings to a substring match. Matching is therefore
 *    SUBSTRING matching over folded text (ScriptFolding), which catches ordinary
 *    suffixation — «کتابها» contains «کتاب» — and misses prefixed and broken-plural
 *    forms entirely. Arabic broken plurals («كتاب» → «كتب») share no substring at
 *    all and will simply not be found.
 *  - No synonym or "related keyphrase" recognition, for the same reason.
 *  - No readability score. Flesch–Kincaid and every derivative of it counts
 *    syllables by English vowel heuristics; run over Persian it produces a number
 *    that varies with nothing meaningful. A confident-looking wrong number is worse
 *    than no number, so there is none.
 *  - No passive-voice or sentence-length grading: both need a part-of-speech tagger.
 *  - No duplicate-keyphrase detection across records. It needs no NLP and would be
 *    genuinely useful, but it needs a LIKE scan of a JSON column across every
 *    locale on every keystroke the panel re-renders; it belongs behind a report, not
 *    behind a live form field.
 *
 * So what is left is only what can be computed exactly: does this phrase literally
 * appear in the places that matter, how often, and is there enough text with enough
 * structure. Each check's translated description says what it looked at, because a
 * check whose meaning the editor has to guess is how a score becomes superstition.
 *
 * Every result is ADVISORY — the same stance as HasSeoMeta::seoWarningsFor(). None
 * of it blocks a save. A deliberate keyphrase-free news flash is a legitimate
 * editorial choice.
 */
class SeoAnalyser
{
    public const STATUS_PASS = 'pass';

    public const STATUS_WARN = 'warn';

    /**
     * Score bands. Deliberately coarse: the difference between 71 and 74 is noise,
     * and a band is what an editor can act on.
     */
    public const BAND_GOOD = 'good';

    public const BAND_FAIR = 'fair';

    public const BAND_POOR = 'poor';

    public function __construct(private readonly SlugGenerator $slugs) {}

    /**
     * @return array{
     *     keyphrase: string,
     *     score: int|null,
     *     band: string|null,
     *     checks: list<array{id: string, status: string, value: string|null}>
     * }
     */
    public function analyse(SeoAnalysisSubject $subject): array
    {
        $checks = [];

        $plain = $subject->hasBody ? TipTap::toPlainText($subject->body) : '';
        $words = ScriptFolding::wordCount($plain);

        if ($subject->keyphrase !== '') {
            $checks = array_merge($checks, $this->keyphraseChecks($subject, $plain, $words));
        }

        if ($subject->hasBody) {
            $checks[] = $this->contentLengthCheck($words);
            $checks[] = $this->headingCheck($subject->body, $words);
        }

        return [
            'keyphrase' => $subject->keyphrase,
            'score' => $this->score($checks),
            'band' => $this->band($this->score($checks)),
            'checks' => $checks,
        ];
    }

    /**
     * Checks that only mean anything once a keyphrase exists.
     *
     * @return list<array{id: string, status: string, value: string|null}>
     */
    private function keyphraseChecks(SeoAnalysisSubject $subject, string $plain, int $words): array
    {
        $checks = [];

        /*
         * The meta title. The single strongest on-page signal, and the one an
         * editor can fix in five seconds — so it is first.
         */
        $checks[] = $this->check(
            'keyphrase_in_title',
            ScriptFolding::contains($subject->metaTitle, $subject->keyphrase),
        );

        /*
         * The meta description. NOT a ranking factor, and the label says so: what it
         * buys is the phrase rendered bold in the search snippet, which moves
         * click-through. Overstating it would be the kind of folk SEO this class is
         * trying not to spread.
         */
        $checks[] = $this->check(
            'keyphrase_in_description',
            ScriptFolding::contains($subject->metaDescription, $subject->keyphrase),
        );

        /*
         * The slug, compared through SlugGenerator rather than ScriptFolding. A slug
         * has already been through slug normalisation — spaces are hyphens, digits
         * are ASCII — so folding the keyphrase the SAME way is the only comparison
         * that can succeed. Folding both with ScriptFolding would compare
         * «راهنمای خرید» against «راهنمای-خرید» and always fail, which is a check
         * that can never pass: worse than no check.
         */
        $checks[] = $this->check(
            'keyphrase_in_slug',
            $this->slugContainsKeyphrase($subject),
        );

        if ($subject->hasBody) {
            $opening = $this->opening($plain);

            /*
             * The OPENING of the text, not strictly the first <p>. Named that way in
             * the UI too, because it is what is actually measured: the first N words
             * of the flattened body, which may include a lead-in heading or a callout.
             * A Persian article frequently opens with a hero or a lead block rather
             * than a paragraph node, so testing the literal first paragraph node
             * would report "missing" on articles that open with the keyphrase in the
             * largest type on the page.
             */
            $checks[] = $this->check(
                'keyphrase_in_opening',
                ScriptFolding::contains($opening, $subject->keyphrase),
            );

            $headings = TipTap::headings($subject->body);

            $checks[] = $this->check(
                'keyphrase_in_heading',
                array_filter(
                    $headings,
                    fn (string $heading): bool => ScriptFolding::contains($heading, $subject->keyphrase),
                ) !== [],
            );

            $checks[] = $this->densityCheck($subject, $plain, $words);
        }

        return $checks;
    }

    /**
     * Keyphrase occurrences as a share of the body's words.
     *
     * The band is wide (0.5%–2.5% by default) and that is not laziness. Two known
     * inaccuracies both point the same way: substring matching UNDERCOUNTS inflected
     * Persian forms, and folding ZWNJ to a space INFLATES the word denominator for
     * compound-heavy prose. Both push the computed density below the real one, so a
     * narrow band would nag editors who have written perfectly normal copy. The
     * upper bound is the one that earns its keep: a density above it is usually a
     * phrase repeated mechanically, which is what a spam classifier looks for.
     *
     * @return array{id: string, status: string, value: string|null}
     */
    private function densityCheck(SeoAnalysisSubject $subject, string $plain, int $words): array
    {
        $occurrences = ScriptFolding::occurrences($plain, $subject->keyphrase);

        if ($words === 0) {
            return $this->check('keyphrase_density', false, '0');
        }

        $keyphraseWords = max(1, ScriptFolding::wordCount($subject->keyphrase));

        // Occurrences are counted in PHRASES but the denominator is in WORDS, so a
        // three-word keyphrase appearing five times occupies fifteen of them.
        $density = ($occurrences * $keyphraseWords / $words) * 100;

        $min = (float) config('cms.seo.analysis.density_min', 0.5);
        $max = (float) config('cms.seo.analysis.density_max', 2.5);

        return $this->check(
            'keyphrase_density',
            $density >= $min && $density <= $max,
            number_format($density, 1).'%',
        );
    }

    /**
     * Is there enough text to rank at all.
     *
     * Counted in words, not characters: a character threshold applied to Persian and
     * to English means two different amounts of writing.
     *
     * @return array{id: string, status: string, value: string|null}
     */
    private function contentLengthCheck(int $words): array
    {
        $minimum = (int) config('cms.seo.analysis.min_words', 300);

        return $this->check('content_length', $words >= $minimum, (string) $words);
    }

    /**
     * Structure: long prose needs subheadings, and no single run of prose should be
     * unbroken past a limit.
     *
     * Both halves are one check because they answer one editorial question ("is this
     * readable in chunks?") and splitting them would let a document with one heading
     * and a 2,000-word section score half marks for structure it does not have.
     *
     * A short body passes trivially — a 200-word news flash needs no subheadings,
     * and demanding them would make the score advise padding.
     *
     * @return array{id: string, status: string, value: string|null}
     */
    private function headingCheck(mixed $body, int $words): array
    {
        $threshold = (int) config('cms.seo.analysis.max_section_words', 300);

        if ($words <= $threshold) {
            return $this->check('heading_distribution', true, (string) $words);
        }

        $headings = TipTap::headings($body);

        if ($headings === []) {
            return $this->check('heading_distribution', false, '0');
        }

        // sections() pairs each heading with the prose that follows it, so the
        // longest section is exactly "the longest run the reader gets with no
        // signpost" — the thing being measured. Text before the first heading is not
        // returned by sections(), which is why the no-heading case is handled above
        // rather than falling through to a max of zero.
        $longest = 0;

        foreach (TipTap::sections($body) as $section) {
            $longest = max($longest, ScriptFolding::wordCount($section['text']));
        }

        return $this->check('heading_distribution', $longest <= $threshold, (string) $longest);
    }

    /**
     * Whether the slug carries the keyphrase, compared in slug space.
     */
    private function slugContainsKeyphrase(SeoAnalysisSubject $subject): bool
    {
        $slug = $this->slugs->generate($subject->slug, $subject->locale);
        $keyphrase = $this->slugs->generate($subject->keyphrase, $subject->locale);

        return $slug !== '' && $keyphrase !== '' && str_contains($slug, $keyphrase);
    }

    /**
     * The first N words of the flattened body.
     */
    private function opening(string $plain): string
    {
        $limit = (int) config('cms.seo.analysis.opening_words', 50);

        $words = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return '';
        }

        return implode(' ', array_slice($words, 0, $limit));
    }

    /**
     * @return array{id: string, status: string, value: string|null}
     */
    private function check(string $id, bool $passed, ?string $value = null): array
    {
        return [
            'id' => $id,
            'status' => $passed ? self::STATUS_PASS : self::STATUS_WARN,
            'value' => $value,
        ];
    }

    /**
     * Percentage of applicable checks passed, or null when nothing applied.
     *
     * null rather than 0 or 100: a Category with no keyphrase and no prose has
     * nothing to score, and inventing either number would be a claim about content
     * that was never examined.
     *
     * @param  list<array{id: string, status: string, value: string|null}>  $checks
     */
    private function score(array $checks): ?int
    {
        if ($checks === []) {
            return null;
        }

        $passed = count(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] === self::STATUS_PASS,
        ));

        return (int) round($passed / count($checks) * 100);
    }

    private function band(?int $score): ?string
    {
        return match (true) {
            $score === null => null,
            $score >= 80 => self::BAND_GOOD,
            $score >= 50 => self::BAND_FAIR,
            default => self::BAND_POOR,
        };
    }
}
