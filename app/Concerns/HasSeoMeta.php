<?php

declare(strict_types=1);

namespace App\Concerns;

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
     * Google truncates title tags at roughly 60 characters and descriptions at
     * roughly 155. These are advisory limits surfaced in the panel, not hard
     * validation — a deliberately longer title is a legitimate editorial choice.
     */
    public const META_TITLE_ADVISORY_LIMIT = 60;

    public const META_DESCRIPTION_ADVISORY_LIMIT = 155;

    public function metaTitleFor(string $locale): string
    {
        $explicit = $this->getTranslation('meta_title', $locale, useFallbackLocale: false);

        if (filled($explicit)) {
            return (string) $explicit;
        }

        // Fall back to the display title in the same locale, then to the source
        // locale's title. Returning an empty <title> would be worse than
        // returning the Persian one on an English page.
        $title = $this->getTranslation('title', $locale, useFallbackLocale: true);

        return (string) ($title ?? '');
    }

    public function metaDescriptionFor(string $locale): string
    {
        $explicit = $this->getTranslation('meta_description', $locale, useFallbackLocale: false);

        if (filled($explicit)) {
            return (string) $explicit;
        }

        $excerpt = $this->getTranslation('excerpt', $locale, useFallbackLocale: true);

        if (filled($excerpt)) {
            return Str::limit(strip_tags((string) $excerpt), self::META_DESCRIPTION_ADVISORY_LIMIT, '');
        }

        return '';
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
        } elseif (mb_strlen($title) > self::META_TITLE_ADVISORY_LIMIT) {
            $warnings[] = 'title_too_long';
        }

        if ($description === '') {
            $warnings[] = 'missing_description';
        } elseif (mb_strlen($description) > self::META_DESCRIPTION_ADVISORY_LIMIT) {
            $warnings[] = 'description_too_long';
        }

        return $warnings;
    }
}
