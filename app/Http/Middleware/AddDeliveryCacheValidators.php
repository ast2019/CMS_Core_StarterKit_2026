<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every Delivery read an ETag, and answers an unchanged one with 304.
 *
 * The Delivery API had a server-side cache (DeliveryCache) and nothing at the HTTP
 * layer: `Cache-Control: no-cache, private` on every response, no validator, so a
 * frontend, a CDN and a browser all re-fetched and re-transferred a payload they
 * already held, every time. For a headless CMS that is the wrong place to stop — the
 * consumers are a Next.js server rendering the same article for many visitors and a
 * CDN in front of it, and both are built to revalidate cheaply if you let them.
 *
 * WHY AN ETAG AND NOT LAST-MODIFIED.
 *
 * Last-Modified is the obvious other half, and it is deliberately absent. A correct
 * value would have to be the newest modification of everything the payload contains,
 * not of the record it is named after: an article's payload carries its category's
 * name, its author's name, its media's alt text and the site settings, so
 * `$content->updated_at` is not when that body last changed. Sending it anyway would
 * produce a 304 for a payload that HAS changed — a frontend pinned to stale content
 * with no way to notice. The ETag has no such problem because it is a hash of the body
 * actually being sent, so it is right by construction.
 *
 * WHY THE MAX-AGE IS NOT THE SERVER CACHE TTL.
 *
 * `cms.api.delivery.cache_ttl` (300s) is how long this application may reuse its own
 * computed payload — and the observers invalidate it the moment an editor saves, so it
 * is a ceiling that rarely applies. An HTTP max-age is different in kind: once a
 * response is in a CDN or a browser, NOTHING here can invalidate it, so the same 300
 * would mean an editor's correction stays invisible for five minutes even though the
 * server discarded its cache instantly. `http_max_age` is therefore its own, much
 * smaller number: short enough that a publish propagates promptly, long enough that a
 * burst of requests for the same article is served without touching PHP. Past it, the
 * ETag makes revalidation a 304 with no body rather than a full re-transfer.
 *
 * ORDER MATTERS. This is registered as the INNERMOST delivery middleware, so on the
 * way out AuthenticateDeliveryApi runs after it and its `private, no-store` wins when
 * key enforcement is on. That is the right precedence: with a required key, responses
 * vary per client and must not be held in a shared cache at all, whatever this would
 * otherwise have said.
 */
class AddDeliveryCacheValidators
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        /*
         * GET and HEAD only, and only a plain 200.
         *
         * A 4xx carries an error message that must not be cached or revalidated as if it
         * were content, and a redirect or a streamed response has no stable body to
         * hash. The contact form POST lives in this API too and must never land here.
         */
        if (! $request->isMethodCacheable() || $response->getStatusCode() !== Response::HTTP_OK) {
            return $response;
        }

        $content = $response->getContent();

        // A streamed or binary response returns false; there is nothing to hash.
        if (! is_string($content) || $content === '') {
            return $response;
        }

        /*
         * xxh128 rather than md5 or sha256: an ETag is an opaque equality token, not a
         * security claim, so what matters is speed over a body this size and a collision
         * rate low enough to be irrelevant. It is the same hash DeliveryCache uses for
         * its key fingerprints, so the application has one answer to "how do we
         * fingerprint a payload".
         */
        $response->setEtag(hash('xxh128', $content));

        $maxAge = max(0, (int) config('cms.api.delivery.http_max_age', 60));

        if ($maxAge > 0) {
            $response->headers->set('Cache-Control', "public, max-age={$maxAge}");
        } else {
            /*
             * Zero means "always revalidate" rather than "do not cache": the ETag is
             * still sent, so a client keeps its copy and pays for a 304 instead of a
             * whole body. `no-cache` is exactly that instruction, despite its name.
             */
            $response->headers->set('Cache-Control', 'public, no-cache');
        }

        /*
         * isNotModified() compares If-None-Match against the ETag just set and, when
         * they agree, strips the body and switches the status to 304 while keeping the
         * headers a 304 is allowed to carry. Using Symfony's own implementation rather
         * than comparing by hand is what gets the details right — weak comparison, the
         * `*` wildcard, and a list of several candidate tags.
         */
        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }
}
