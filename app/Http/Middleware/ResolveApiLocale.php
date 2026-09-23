<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale for the Delivery API.
 *
 * Requirements 5.2, 5.5.
 *
 * Resolution order, most to least explicit:
 *   1. a `locale` query parameter or route segment — the frontend said so
 *   2. the Accept-Language header — the browser said so
 *   3. the configured source locale
 *
 * An UNSUPPORTED locale is a 400, not a silent fallback. Falling back quietly
 * would mean a frontend requesting `?locale=de` receives Persian with a 200 and
 * caches it, and the bug surfaces as "the German site shows Persian" weeks later
 * rather than at the first request.
 */
class ResolveApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = (array) config('cms.locales.supported', ['fa']);
        $requested = $this->requestedLocale($request);

        if ($requested !== null && ! in_array($requested, $supported, true)) {
            return response()->json([
                'message' => "Unsupported locale [{$requested}].",
                'errors' => [
                    'locale' => [
                        'Supported locales: '.implode(', ', $supported).'.',
                    ],
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        $negotiates = (bool) config('cms.locales.negotiate_from_header', false);

        $locale = $requested
            ?? ($negotiates ? $this->fromAcceptLanguage($request, $supported) : null)
            ?? (string) config('cms.locales.source', 'fa');

        app()->setLocale($locale);
        $request->attributes->set('cms_locale', $locale);

        $response = $next($request);

        // Tells caches and clients which locale this body actually is.
        $response->headers->set('Content-Language', $locale);

        /*
         * Vary ONLY when the header can actually influence the response. Sending it
         * unconditionally would fragment every shared cache by Accept-Language even
         * though the body does not depend on it — the header value varies per
         * browser, so the hit rate collapses for no benefit.
         */
        if ($negotiates) {
            $response->headers->set('Vary', 'Accept-Language');
        }

        return $response;
    }

    private function requestedLocale(Request $request): ?string
    {
        $locale = $request->route('locale') ?? $request->query('locale');

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    /**
     * @param  list<string>  $supported
     */
    private function fromAcceptLanguage(Request $request, array $supported): ?string
    {
        // getPreferredLanguage compares full tags, so 'fa-IR' would not match the
        // supported 'fa'. Matching on the primary subtag is what makes a real
        // browser header work.
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(explode('_', str_replace('-', '_', $language))[0]);

            if (in_array($primary, $supported, true)) {
                return $primary;
            }
        }

        return null;
    }
}
