<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The four things every Delivery read endpoint has to get right.
 *
 * Extracted rather than copied: the locale read, the page-size bound and the
 * slug-with-fallback lookup were already duplicated across the Delivery
 * controllers, and adding three more endpoints would have made a fourth and fifth
 * copy of rules that must not diverge — a per_page bound that one endpoint forgets
 * is a denial-of-service vector, and a fallback lookup that one endpoint implements
 * differently is a locale that silently 404s for some content types and not others.
 */
trait ResolvesDeliveryRequest
{
    /**
     * The locale ResolveApiLocale settled on, or the app default if the middleware
     * did not run (a console or test call reaching the controller directly).
     */
    protected function locale(Request $request): string
    {
        $locale = $request->attributes->get('cms_locale');

        return is_string($locale) ? $locale : app()->getLocale();
    }

    protected function sourceLocale(): string
    {
        return (string) config('cms.locales.source', 'fa');
    }

    /**
     * Page size, bounded.
     *
     * An unbounded `per_page` is a denial-of-service vector on a public endpoint:
     * `?per_page=100000` would serialise an entire archive, with every relation, on
     * one request.
     */
    protected function perPage(Request $request, int $default = 15, int $max = 100): int
    {
        return max(1, min($request->integer('per_page', $default), $max));
    }

    /**
     * Refuse to serve an endpoint whose module is switched off.
     *
     * config/cms.php promises that "a disabled module registers no routes, no
     * Filament resource, and contributes no sitemap entries" (Requirement 1.1), and
     * until now only the Filament resources, search and redirects honoured it — a
     * site with the gallery module off still had its galleries readable over the
     * API, which makes the toggle a lie in the one place a consumer can see.
     *
     * 404 rather than 403: a disabled module does not exist on this site, and saying
     * "forbidden" would tell a caller there is something there to get access to.
     */
    protected function ensureModuleEnabled(string $module): void
    {
        if (! (bool) config("cms.modules.{$module}", true)) {
            throw new NotFoundHttpException("The [{$module}] module is not enabled on this site.");
        }
    }

    /**
     * Resolve a record by its per-locale slug, falling back to the source locale.
     *
     * Blueprint §2 requires fallback display when a translation is missing, and an
     * untranslated record has no slug in the target locale — so a
     * requested-locale-only lookup would make fallback unreachable for exactly the
     * records that need it.
     *
     * The source-locale attempt is a SECOND step, not a merged OR: a slug that
     * exists in the requested locale must always win, or a Persian slug could
     * shadow a different record that legitimately owns that slug in English.
     *
     * This does not create a duplicate-content problem, because the response marks
     * `is_fallback` and names the real locale, and the hreflang/canonical data tells
     * crawlers which URL is authoritative (Requirements 5.5, 7.2).
     *
     * $query is a factory rather than a Builder because the fallback attempt needs a
     * SECOND query: reusing one builder would stack both slug conditions and match
     * nothing.
     *
     * @template TModel of Model
     *
     * @param  Closure(): Builder<TModel>  $query
     * @return TModel|null
     */
    protected function resolveBySlug(Closure $query, string $locale, string $slug): ?Model
    {
        $record = $query()->whereJsonContainsLocale('slug', $locale, $slug)->first();

        if ($record !== null) {
            return $record;
        }

        $source = $this->sourceLocale();

        if ($locale === $source) {
            return null;
        }

        return $query()->whereJsonContainsLocale('slug', $source, $slug)->first();
    }
}
