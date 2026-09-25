<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\Page;
use Illuminate\Database\Eloquent\Model;

/**
 * Public URLs for CMS records.
 *
 * Requirements 7.2, 7.4, 7.5.
 *
 * Single source of truth for the site's URL shape. The redirect engine, the
 * sitemap generator, the hreflang builder and the navigation resolver must all
 * agree — a generated 301 that points somewhere the sitemap does not list is a
 * silently broken link, and three near-identical path builders is how that happens.
 */
class UrlBuilder
{
    /**
     * Path segment per content type. `null` means the slug sits directly under the
     * locale, which is how static pages work (/fa/about rather than /fa/page/about).
     *
     * @var array<class-string, string|null>
     */
    private const SEGMENTS = [
        Content::class => 'news',
        Gallery::class => 'gallery',
        Category::class => 'category',
        Page::class => null,
    ];

    /**
     * Absolute canonical URL for a record in a locale, or null when it has no slug
     * there.
     *
     * Returns null rather than falling back to another locale's slug: a canonical
     * URL that resolves to different content than the page claiming it is worse than
     * no canonical at all.
     */
    public function canonicalFor(Model $record, string $locale): ?string
    {
        $path = $this->pathFor($record, $locale);

        return $path === null ? null : $this->absolute($path);
    }

    /**
     * Locale-prefixed path for a record, or null when the locale has no slug.
     */
    public function pathFor(Model $record, string $locale): ?string
    {
        $slug = $record->getTranslation('slug', $locale, useFallbackLocale: false);

        if (blank($slug)) {
            return null;
        }

        return $this->pathForSlug($record::class, $locale, (string) $slug);
    }

    /**
     * Path for an explicit slug, used by the redirect engine where the old slug no
     * longer exists on the record.
     *
     * @param  class-string  $modelClass
     */
    public function pathForSlug(string $modelClass, string $locale, string $slug): string
    {
        $segment = self::SEGMENTS[$modelClass] ?? null;

        return $segment === null
            ? "/{$locale}/{$slug}"
            : "/{$locale}/{$segment}/{$slug}";
    }

    /**
     * Locale home page, e.g. /fa.
     */
    public function localeHome(string $locale): string
    {
        return $this->absolute("/{$locale}");
    }

    /**
     * A fragment URI on one of this site's URLs, e.g. https://site/fa/news/x#article.
     *
     * JSON-LD nodes need stable @id values, and the convention is a fragment on the
     * URL of the thing being described. That still has to agree with the rest of the
     * site's URL shape — an @id assembled by hand somewhere in the SEO layer is the
     * fourth near-identical path builder this class exists to prevent, and a @graph
     * whose nodes are addressed inconsistently cross-references nothing.
     */
    public function withFragment(string $url, string $fragment): string
    {
        return rtrim($url, '#').'#'.ltrim($fragment, '#');
    }

    /**
     * Absolute URL from a root-relative path.
     *
     * Built from the FRONTEND base URL, which may differ from APP_URL: this Core is
     * headless, so the public site is a separate deployment and its canonical and
     * sitemap URLs must point at the frontend rather than at the API host. Falls back
     * to APP_URL when no frontend URL is configured, which is the single-host case.
     */
    public function absolute(string $path): string
    {
        $base = rtrim((string) (config('cms.frontend_url') ?: config('app.url')), '/');

        return $base.'/'.ltrim($path, '/');
    }

    /**
     * Whether a model type has a public URL at all.
     *
     * @param  class-string  $modelClass
     */
    public function isRoutable(string $modelClass): bool
    {
        return array_key_exists($modelClass, self::SEGMENTS);
    }
}
