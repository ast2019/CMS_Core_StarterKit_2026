<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Redirect;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The redirect engine.
 *
 * Requirement 7.5.
 *
 * Runs early and matches the incoming path against the `redirects` table. The whole
 * table is cached as one map rather than queried per request: redirects are read on
 * potentially every 404 and written rarely, so one cache entry busted on write beats
 * a database round trip on every miss.
 */
class HandleRedirects
{
    /**
     * Maximum hops to follow when one redirect's target is itself redirected.
     *
     * Chains happen legitimately — an article's slug changes twice — but each hop
     * costs the visitor a round trip and crawlers stop following after a few. So the
     * chain is RESOLVED here to its final destination and the visitor gets a single
     * redirect, while the guard stops a cycle the loop check missed from spinning.
     */
    private const MAX_HOPS = 5;

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('cms.modules.redirect', true)) {
            return $next($request);
        }

        if ($this->isExcluded($request)) {
            return $next($request);
        }

        $path = Redirect::normalisePath($request->getPathInfo());
        $map = $this->map();

        if (! isset($map[$path])) {
            return $next($request);
        }

        $resolved = $this->resolve($map, $path);

        if ($resolved === null) {
            // A cycle the validation layer did not catch. Serving the request normally
            // is the only safe outcome: following it would loop the browser, and a 500
            // would take down a URL that might otherwise still resolve.
            return $next($request);
        }

        [$target, $status] = $resolved;

        $this->recordHit($path);

        return new RedirectResponse(
            $this->withQueryString($request, $target),
            $status,
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
     *    consumer following it would parse a redirect page as data.
     *  - The admin panel and its assets: a stale redirect row matching a panel path
     *    would lock editors out of the CMS, with the redirect table itself unreachable.
     *  - Health and storage paths: infrastructure checks and local media must resolve
     *    regardless of content-level redirects (RULE #9 serves media from /storage).
     */
    private function isExcluded(Request $request): bool
    {
        $panel = trim((string) config('cms.brand.panel_path', 'admin'), '/');

        return $request->is(
            'api/*',
            'up',
            'storage/*',
            'livewire/*',
            $panel,
            $panel.'/*',
        );
    }

    /**
     * Follow the chain to its final destination.
     *
     * @param  array<string, array{to: string, type: int}>  $map
     * @return array{0: string, 1: int}|null
     */
    private function resolve(array $map, string $path): ?array
    {
        $seen = [$path => true];
        $current = $path;

        // The FIRST hop's status code is what the visitor is told, because that is the
        // relationship being described: if the original move was permanent, the whole
        // chain is permanent regardless of how later hops were recorded.
        $status = $map[$path]['type'];

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $target = Redirect::normalisePath($map[$current]['to']);

            if (! isset($map[$target])) {
                return [$target, $status];
            }

            if (isset($seen[$target])) {
                return null;
            }

            $seen[$target] = true;
            $current = $target;
        }

        // Ran out of hops without terminating: treat as a loop rather than sending the
        // visitor somewhere arbitrary mid-chain.
        return null;
    }

    /**
     * Preserve the incoming query string unless the target defines its own.
     *
     * Dropping it silently would break campaign tracking and paginated links across
     * every renamed URL, which is a wide and hard-to-attribute regression.
     */
    private function withQueryString(Request $request, string $target): string
    {
        /*
         * The RAW query string, not Request::getQueryString(): Symfony normalises that
         * one by sorting parameters alphabetically, so ?utm_source=x&page=2 comes back
         * as ?page=2&utm_source=x. Functionally equivalent, but it means the URL a
         * visitor lands on differs from the one they clicked, which shows up as
         * mismatched URLs in analytics and makes redirects harder to debug from logs.
         */
        $query = $request->server('QUERY_STRING');

        if (! is_string($query) || $query === '') {
            $query = (string) $request->getQueryString();
        }

        if ($query === '' || str_contains($target, '?')) {
            return $target;
        }

        return $target.'?'.$query;
    }

    /**
     * from_path => [to, type], cached whole.
     *
     * @return array<string, array{to: string, type: int}>
     */
    private function map(): array
    {
        /** @var array<string, array{to: string, type: int}> */
        return Cache::rememberForever(Redirect::CACHE_KEY, function (): array {
            return Redirect::query()
                ->get(['from_path', 'to_path', 'type'])
                ->mapWithKeys(fn (Redirect $redirect): array => [
                    $redirect->from_path => [
                        'to' => $redirect->to_path,
                        'type' => $redirect->type->statusCode(),
                    ],
                ])
                ->all();
        });
    }

    /**
     * Increment the hit counter without loading or saving a model.
     *
     * This runs on a hot path, so it is a single UPDATE rather than a read-modify-write
     * — and it deliberately does not fire model events, which would invalidate the
     * cache this middleware just read and make every redirect re-query the table.
     */
    private function recordHit(string $path): void
    {
        Redirect::query()
            ->where('from_path', $path)
            ->update([
                'hits' => DB::raw('hits + 1'),
                'last_hit_at' => now(),
            ]);
    }
}
