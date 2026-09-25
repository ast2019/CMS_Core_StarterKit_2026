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
     * The module toggle that owns each routable type.
     *
     * Kept beside the segment map rather than in the navigation layer because the
     * two answers belong to the same question: "does this record have a public URL
     * on THIS site?" A site with the gallery module off answers 404 on
     * /api/v1/galleries/{slug} (Requirement 1.1), so the gallery URL shape still
     * exists while no gallery URL resolves — and a menu or a sitemap that keeps
     * advertising it produces exactly the broken link this class exists to prevent.
     *
     * @var array<class-string, string>
     */
    private const MODULES = [
        Content::class => 'content',
        Gallery::class => 'gallery',
        Category::class => 'category',
        Page::class => 'page',
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
        /*
         * The homepage owns the locale root and NOT /{locale}/{slug}. Checked before
         * the slug is read, because the slug is irrelevant to the answer: a homepage
         * with no English slug is still reachable at /en, since the locale root is the
         * site's entry point rather than a translated address.
         *
         * A homepage reachable at both /fa and /fa/{slug} is a duplicate-content bug,
         * and it is the kind that is invisible until a crawler finds it. Answering
         * here means the canonical, the sitemap, the hreflang cluster, the redirect
         * suggestions and the navigation resolver all agree, because all five ask this
         * class.
         */
        if ($this->hasLocaleRootUrl($record)) {
            return $this->localeHomePath($locale);
        }

        $slug = $record->getTranslation('slug', $locale, useFallbackLocale: false);

        if (blank($slug)) {
            return null;
        }

        return $this->pathForSlug($record::class, $locale, (string) $slug);
    }

    /**
     * Path for a record whose slug is supplied rather than read off it.
     *
     * The record-aware counterpart of pathForSlug(), for the two callers that hold
     * both: the redirect engine (which has the OLD slug, no longer on the record) and
     * the navigation resolver (which reads the slug WITH locale fallback). Both would
     * otherwise send the homepage to /{locale}/{slug} — a menu item pointing at the
     * homepage would link to a URL the sitemap does not list, and renaming the
     * homepage's slug would generate a 301 from a URL that was never public.
     */
    public function pathForRecordSlug(Model $record, string $locale, string $slug): string
    {
        return $this->hasLocaleRootUrl($record)
            ? $this->localeHomePath($locale)
            : $this->pathForSlug($record::class, $locale, $slug);
    }

    /**
     * Whether this record IS the locale root rather than a page beneath it.
     *
     * Narrow by design: "homepage" is a Page designated by system key (see
     * Page::SYSTEM_HOME), so there is exactly one such record and no other type can
     * claim the root.
     */
    public function hasLocaleRootUrl(Model $record): bool
    {
        return $record instanceof Page && $record->isHomePage();
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
     * Locale home page as an absolute URL, e.g. https://site/fa.
     */
    public function localeHome(string $locale): string
    {
        return $this->absolute($this->localeHomePath($locale));
    }

    /**
     * Locale home page as a root-relative path, e.g. /fa.
     *
     * Separate from localeHome() because navigation needs the relative form (it is
     * rendered by the frontend on its own host) while the sitemap and canonical layers
     * need the absolute one. Splitting them here keeps "/{locale}" written once.
     */
    public function localeHomePath(string $locale): string
    {
        return "/{$locale}";
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

    /**
     * Whether a model type has a public URL *on this deployment*.
     *
     * Stricter than isRoutable(): the type must also belong to an enabled module.
     * A link to a record whose module is switched off is a link to a 404, and
     * Requirement 1.1 says a disabled module contributes nothing public at all.
     *
     * @param  class-string  $modelClass
     */
    public function isPubliclyRoutable(string $modelClass): bool
    {
        if (! $this->isRoutable($modelClass)) {
            return false;
        }

        $module = self::MODULES[$modelClass] ?? null;

        return $module === null || (bool) config("cms.modules.{$module}", true);
    }
}
