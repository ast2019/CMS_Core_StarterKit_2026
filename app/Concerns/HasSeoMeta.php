<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Contracts\HasSeoMetadata;
use App\Contracts\Publishable;
use App\Contracts\TracksTranslationStatus;
use App\Services\Seo\SeoAnalyser;
use App\Services\Seo\SeoAnalysisSubject;
use Illuminate\Support\Str;

/**
 * Per-locale SEO metadata.
 *
 * Requirements 7.1, 7.3.
 *
 * Every getter falls back rather than returning null, because an empty
 * <title> or a missing meta description is worse than an imperfect generated
 * one — and expecting editors to fill three locales x four fields per article
 * guarantees gaps.
 *
 * The advisory limits and the lists of translatable SEO attributes are declared on
 * App\Contracts\HasSeoMetadata rather than here, because a trait constant cannot be
 * read through the trait's name and both the panel and the architecture tests need
 * them. Models using this trait should implement that contract, which is what makes
 * the constants part of their public surface.
 *
 * The keyphrase ANALYSIS is not in here. This trait gathers the values (only the
 * model knows which attributes it declares, and asking spatie for an undeclared one
 * throws) and App\Services\Seo\SeoAnalyser applies the checks, so the checks are
 * testable without a database and the panel and the Delivery API cannot compute
 * different scores.
 */
trait HasSeoMeta
{
    /*
     * The lists of translatable SEO attributes used to live here as trait constants
     * and are now on HasSeoMetadata — SEO_TRANSLATABLE_ATTRIBUTES and
     * OPTIONAL_SEO_TRANSLATABLE_ATTRIBUTES. Same reason the advisory limits moved
     * there, and it was found the same way: the architecture test asserting the lists
     * could not read them. PHP does not allow a trait constant to be reached through
     * the trait's name, so a constant here is invisible to every caller that is
     * generic over the model — including the test that makes it more than a comment.
     */

    /**
     * Attributes the meta title falls back to, in order, when the editor set no
     * explicit one.
     *
     * Not a single hardcoded `title`, because the four models using this trait do
     * not agree on the name of their display attribute: a Category calls it
     * `name`. Reaching for `title` on a Category is not a cosmetic mismatch —
     * spatie's getTranslation() throws AttributeIsNotTranslatable for an
     * attribute the model never registered, so the unguarded version turned a
     * missing meta title into a 500.
     *
     * @return list<string>
     */
    protected function seoTitleFallbackAttributes(): array
    {
        return ['title', 'name'];
    }

    /**
     * Attributes the meta description falls back to, in order.
     *
     * Only Content has an `excerpt`; a Gallery and a Category carry a
     * `description` instead, and a Page has neither. Listing all of them and
     * skipping whatever the model does not declare is what makes the fallback
     * safe on every model rather than only on the one it was written against.
     *
     * @return list<string>
     */
    protected function seoDescriptionFallbackAttributes(): array
    {
        return ['excerpt', 'description'];
    }

    public function metaTitleFor(string $locale): string
    {
        $explicit = $this->getTranslation('meta_title', $locale, useFallbackLocale: false);

        if (filled($explicit)) {
            return (string) $explicit;
        }

        // Fall back to the display title in the same locale, then to the source
        // locale's title. Returning an empty <title> would be worse than
        // returning the Persian one on an English page.
        return (string) ($this->firstTranslatableValue($this->seoTitleFallbackAttributes(), $locale) ?? '');
    }

    public function metaDescriptionFor(string $locale): string
    {
        $explicit = $this->getTranslation('meta_description', $locale, useFallbackLocale: false);

        if (filled($explicit)) {
            return (string) $explicit;
        }

        $fallback = $this->firstTranslatableValue($this->seoDescriptionFallbackAttributes(), $locale);

        if (filled($fallback)) {
            return Str::limit(strip_tags((string) $fallback), HasSeoMetadata::META_DESCRIPTION_ADVISORY_LIMIT, '');
        }

        return '';
    }

    /**
     * Attributes that may hold this model's prose, in order.
     *
     * Same guarded-list pattern as the fallbacks above, for the same reason: Content
     * calls its body `body` and Page calls it `blocks` (the column name the blueprint
     * chose), so reaching for either unconditionally throws on the other model. Only
     * the first one the model actually declares is read.
     *
     * A model declaring none — Category, Gallery — simply has no prose, and the
     * keyphrase analysis omits the checks that need it rather than failing them.
     *
     * @return list<string>
     */
    protected function seoBodyAttributes(): array
    {
        return ['body', 'blocks'];
    }

    /**
     * The editor's focus keyphrase for a locale, or '' when unset or unsupported.
     *
     * No fallback to another locale, deliberately. A keyphrase is the phrase this
     * locale is written to be found by; borrowing the Persian one for the English tab
     * would score the English copy against a phrase that does not appear in it and
     * report five failures the editor cannot fix.
     */
    public function focusKeyphraseFor(string $locale): string
    {
        return trim((string) ($this->explicitTranslation('focus_keyphrase', $locale) ?? ''));
    }

    /**
     * Whether this model carries a focus keyphrase at all.
     *
     * Read off the model's own $translatable rather than from a list here, so
     * SeoSection and the API agree with the model by construction and adding the
     * column to another type needs no change in either.
     */
    public function seoSupportsFocusKeyphrase(): bool
    {
        return in_array('focus_keyphrase', $this->getTranslatableAttributes(), true);
    }

    /**
     * OG title for a locale: the per-record override, else the meta title.
     *
     * The override exists because the two strings have different jobs. A meta title
     * competes in a results page against nine others and wants the keyphrase near the
     * front; a social card is read by someone scrolling a feed and can afford to be
     * conversational. Until now the OG values were always derived, so the only way to
     * have both was to compromise on one.
     *
     * Falls back rather than returning empty, for the same reason every getter in this
     * trait does: a card with no title renders as a bare URL.
     */
    public function ogTitleFor(string $locale): string
    {
        $explicit = $this->explicitTranslation('og_title', $locale);

        return filled($explicit) ? (string) $explicit : $this->metaTitleFor($locale);
    }

    public function ogDescriptionFor(string $locale): string
    {
        $explicit = $this->explicitTranslation('og_description', $locale);

        return filled($explicit) ? (string) $explicit : $this->metaDescriptionFor($locale);
    }

    /**
     * Focus-keyphrase and content analysis for a locale (Requirement 7.1).
     *
     * Delegates to App\Services\Seo\SeoAnalyser, which is where the checks live so
     * the panel and the Delivery API cannot compute different scores — the same
     * arrangement seoWarningsFor() has, one layer down. This method's job is only to
     * gather the values, because only the model knows which attributes it declares
     * and asking spatie for an undeclared one throws.
     *
     * @return array{
     *     keyphrase: string,
     *     score: int|null,
     *     band: string|null,
     *     checks: list<array{id: string, status: string, value: string|null}>
     * }
     */
    public function seoAnalysisFor(string $locale): array
    {
        return app(SeoAnalyser::class)->analyse($this->seoAnalysisSubject($locale));
    }

    protected function seoAnalysisSubject(string $locale): SeoAnalysisSubject
    {
        $bodyAttribute = $this->firstDeclaredTranslatable($this->seoBodyAttributes());

        return new SeoAnalysisSubject(
            locale: $locale,
            keyphrase: $this->focusKeyphraseFor($locale),
            // Resolved values, not raw columns: the checks must run against what the
            // frontend will actually render, which for a blank meta_title is the
            // article's own title.
            metaTitle: $this->metaTitleFor($locale),
            metaDescription: $this->metaDescriptionFor($locale),
            slug: (string) ($this->explicitTranslation('slug', $locale) ?? ''),
            body: $bodyAttribute === null
                ? null
                : $this->getTranslation($bodyAttribute, $locale, useFallbackLocale: false),
            hasBody: $bodyAttribute !== null,
        );
    }

    /**
     * A translation of an attribute this model may or may not declare.
     *
     * Returns null for an attribute the model does not register, which is the ONLY
     * safe way to read an optional SEO column: spatie throws
     * AttributeIsNotTranslatable rather than returning null, the same trap
     * firstTranslatableValue() below exists to avoid.
     */
    private function explicitTranslation(string $attribute, string $locale): ?string
    {
        if (! in_array($attribute, $this->getTranslatableAttributes(), true)) {
            return null;
        }

        $value = $this->getTranslation($attribute, $locale, useFallbackLocale: false);

        return is_string($value) ? $value : null;
    }

    /**
     * The first of these attributes the model declares as translatable, or null.
     *
     * @param  list<string>  $attributes
     */
    private function firstDeclaredTranslatable(array $attributes): ?string
    {
        $translatable = $this->getTranslatableAttributes();

        foreach ($attributes as $attribute) {
            if (in_array($attribute, $translatable, true)) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * The first of these attributes that this model actually declares as
     * translatable and holds a value for.
     *
     * The translatable check is the whole point: asking spatie for an attribute
     * the model never registered throws rather than returning null, so probing a
     * list of candidate attributes is only safe when each one is checked first.
     *
     * @param  list<string>  $attributes
     */
    private function firstTranslatableValue(array $attributes, string $locale): ?string
    {
        $translatable = $this->getTranslatableAttributes();

        foreach ($attributes as $attribute) {
            if (! in_array($attribute, $translatable, true)) {
                continue;
            }

            $value = $this->getTranslation($attribute, $locale, useFallbackLocale: true);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Robots directive for a locale.
     *
     * Defaults to noindex when the record is not publicly visible. This is the
     * safety-critical default in the trait: a draft or archived article that
     * leaks a 200 response must not be indexable, and relying on editors to set
     * it per locale would fail eventually.
     */
    public function robotsMetaFor(string $locale): string
    {
        if (! $this->isPubliclyVisible()) {
            return 'noindex, nofollow';
        }

        // A locale whose translation is not sitemap-eligible is also not
        // index-worthy: it is either untranslated or unreviewed machine output,
        // and indexing it risks a quality penalty across the whole locale
        // (Decision D-5).
        if ($this instanceof TracksTranslationStatus && ! $this->isSitemapEligibleFor($locale)) {
            return 'noindex, follow';
        }

        $explicit = $this->getTranslation('robots_meta', $locale, useFallbackLocale: false);

        return filled($explicit) ? (string) $explicit : 'index, follow';
    }

    /**
     * Whether the record is live.
     *
     * Models with an editorial workflow implement Publishable and answer for
     * themselves (which correctly accounts for scheduling — a published record
     * with a future publish_date is not yet live). Models with no workflow, such
     * as a Category or a MenuItem, are always visible.
     */
    public function isPubliclyVisible(): bool
    {
        if ($this instanceof Publishable) {
            return $this->isLive();
        }

        return true;
    }

    /**
     * Advisory SEO warnings for the panel, so an editor sees the problem while
     * writing rather than in a crawl report weeks later.
     *
     * @return list<string>
     */
    public function seoWarningsFor(string $locale): array
    {
        $warnings = [];

        $title = $this->metaTitleFor($locale);
        $description = $this->metaDescriptionFor($locale);

        if ($title === '') {
            $warnings[] = 'missing_title';
        } elseif (mb_strlen($title) > HasSeoMetadata::META_TITLE_ADVISORY_LIMIT) {
            $warnings[] = 'title_too_long';
        }

        if ($description === '') {
            $warnings[] = 'missing_description';
        } elseif (mb_strlen($description) > HasSeoMetadata::META_DESCRIPTION_ADVISORY_LIMIT) {
            $warnings[] = 'description_too_long';
        }

        return $warnings;
    }
}
