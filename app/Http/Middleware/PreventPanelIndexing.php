<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tell crawlers not to index the admin panel.
 *
 * RobotsController already disallows the panel path in robots.txt, and every page
 * behind the login wall is unreachable to a crawler anyway. This covers the gap
 * between those two facts: the LOGIN page is public, robots.txt is a request not a
 * rule, and a link to a client's backoffice pasted into a public place is enough for
 * it to be crawled and indexed.
 *
 * `X-Robots-Tag` is a response header rather than a meta tag on purpose — it applies
 * to every response from the panel, including the ones that are not HTML documents
 * (file downloads, Livewire payloads), and it cannot be missed by a template that
 * forgot to include a partial.
 *
 * `noarchive` is included alongside `noindex, nofollow` so that a crawler which has
 * already seen the page does not keep serving a cached copy of it.
 */
class PreventPanelIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
