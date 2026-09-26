<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Content\RedirectResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The redirect engine, for traffic that reaches THIS host.
 *
 * Requirement 7.5.
 *
 * Worth being precise about what this does and does not cover, because it reads like
 * the whole feature and is not. This Core is headless (blueprint §1): the public site
 * is a separate frontend deployment, and a visitor clicking a stale link hits the old
 * URL THERE, where no middleware of ours runs. This class therefore protects only
 * legacy traffic still arriving at the API/panel host — bookmarks, an older
 * single-host deployment, crawlers that learned these URLs before the split.
 *
 * The frontend gets the same answers from the Delivery API
 * (GET /api/v1/redirects/resolve), and both read App\Services\Content\RedirectResolver
 * so they cannot disagree about chain collapsing, status codes or query strings. See
 * docs/redirects.md for what the frontend has to do with them.
 */
class HandleRedirects
{
    public function __construct(private readonly RedirectResolver $redirects) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->redirects->enabled() || $this->isExcluded($request)) {
            return $next($request);
        }

        $resolved = $this->redirects->resolve($request->getPathInfo());

        /*
         * Null means no redirect applies, or the data contains a cycle. Serving the
         * request normally is the only safe outcome for both: following a cycle would
         * loop the browser, and a 500 would take down a URL that might otherwise still
         * resolve.
         */
        if ($resolved === null) {
            return $next($request);
        }

        $this->redirects->recordHit($resolved['from']);

        return new RedirectResponse(
            $this->redirects->withQueryString($request, $resolved['to']),
            $resolved['status'],
        );
    }

    /**
     * Requests this engine must never rewrite.
     *
     * This middleware is registered globally, because a moved URL has no route and a
     * route-group middleware would therefore never see it. The cost of being global is
     * that it also sees traffic it has no business touching:
     *
     *  - API requests: a 301 would replace a JSON body with an HTML redirect, and a
     *    consumer following it would parse a redirect page as data. This is also why
     *    the frontend cannot simply follow this middleware and must ASK — see
     *    docs/redirects.md.
     *  - The admin panel and its assets: a stale redirect row matching a panel path
     *    would lock editors out of the CMS, with the redirect table itself unreachable.
     *  - Health and storage paths: infrastructure checks and local media must resolve
     *    regardless of content-level redirects (RULE #9 serves media from /storage).
     *  - robots.txt and the sitemap suite: both are machine-facing documents served
     *    from this host, and a content-level redirect matching one of them would take
     *    the site's crawl instructions offline.
     */
    private function isExcluded(Request $request): bool
    {
        $panel = trim((string) config('cms.brand.panel_path', 'admin'), '/');

        return $request->is(
            'api/*',
            'up',
            'storage/*',
            'livewire/*',
            'robots.txt',
            'sitemap.xml',
            'sitemap-*.xml',
            $panel,
            $panel.'/*',
        );
    }
}
