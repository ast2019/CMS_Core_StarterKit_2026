<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Contracts\HasSeoMetadata;
use App\Contracts\Publishable;
use App\Contracts\TracksTranslationStatus;
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
 * The advisory limits are declared on App\Contracts\HasSeoMetadata rather than
 * here, because a trait constant cannot be read through the trait's name and the
 * panel needs to quote the numbers. Models using this trait should implement that
 * contract, which is what makes the constants part of their public surface.
 */
trait HasSeoMeta
{
    /**
     * Attributes this trait expects to be registered as translatable on the
     * model. Asserted by tests/Architecture/SeoMetaTest.php so a model cannot
     * use the trait while leaving the fields non-translatable, which would make
     * every locale share one Persian meta description.
     *
     * @var list<string>
     */
    public const SEO_TRANSLATABLE_ATTRIBUTES = [
        'meta_title',
        'meta_description',
        'robots_meta',
    ];

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
