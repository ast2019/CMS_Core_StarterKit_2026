<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Contracts\TracksTranslationStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Reciprocal hreflang annotations across every locale, plus x-default.
 *
 * Requirement 7.2, blueprint §6.
 *
 * "Reciprocal" is the part that is easy to get wrong and expensive to diagnose:
 * Google ignores an hreflang cluster unless every URL in it points back at all the
 * others, including itself. So this always emits the full set for a record —
 * including a self-referencing entry — rather than only the "other" locales.
 */
class HreflangBuilder
{
    public function __construct(private readonly UrlBuilder $urls) {}

    /**
     * Build the hreflang set for a record.
     *
     * @return array<string, string> locale (or 'x-default') => absolute URL
     */
    public function for(Model $record): array
    {
        $eligible = $this->eligibleLocales($record);

        // A single-locale cluster is not a cluster. Emitting one hreflang pair that
        // only points at itself is noise, and Google treats it as no annotation at
        // all, so returning nothing is both honest and equivalent.
        if (count($eligible) < 2) {
            return [];
        }

        $links = [];

        foreach ($eligible as $locale) {
            $url = $this->urls->canonicalFor($record, $locale);

            if ($url !== null) {
                $links[$locale] = $url;
            }
        }

        if ($links === []) {
            return [];
        }

        /*
         * x-default names the URL served to users whose language does not match any
         * locale in the cluster. The SOURCE locale is the right target: it is the only
         * one guaranteed to hold complete, human-reviewed content.
         */
        $source = (string) config('cms.locales.source', 'fa');

        if (isset($links[$source])) {
            $links['x-default'] = $links[$source];
        }

        return $links;
    }

    /**
     * Locales whose translation is good enough to advertise.
     *
     * Decision D-5 again, applied to hreflang rather than sitemaps. Advertising an
     * untranslated locale is worse than omitting it: the crawler follows the
     * annotation, finds fallback content in another language, and concludes the two
     * URLs are duplicates — which is precisely the penalty hreflang exists to avoid.
     *
     * @return list<string>
     */
    public function eligibleLocales(Model $record): array
    {
        $supported = (array) config('cms.locales.supported', ['fa']);
        $source = (string) config('cms.locales.source', 'fa');

        return array_values(array_filter(
            $supported,
            function (string $locale) use ($record, $source): bool {
                // A noindex URL must not be advertised — the sitemap already drops it,
                // and hreflang pointing at a noindex page is what Search Console flags.
                if ($this->isNoindex($record, $locale)) {
                    return false;
                }

                if (! $record instanceof TracksTranslationStatus) {
                    return true;
                }

                // The source locale is the content itself, never a translation.
                return $locale === $source
                    || $record->isSitemapEligibleFor($locale);
            },
        ));
    }

    private function isNoindex(Model $record, string $locale): bool
    {
        if (! method_exists($record, 'robotsMetaFor')) {
            return false;
        }

        return str_contains(strtolower((string) $record->robotsMetaFor($locale)), 'noindex');
    }

    /**
     * Render the set as HTML <link> tags, for a frontend that wants them ready-made.
     *
     * @return list<string>
     */
    public function toLinkTags(Model $record): array
    {
        $tags = [];

        foreach ($this->for($record) as $hreflang => $url) {
            $tags[] = sprintf(
                '<link rel="alternate" hreflang="%s" href="%s" />',
                e($hreflang),
                e($url),
            );
        }

        return $tags;
    }
}
