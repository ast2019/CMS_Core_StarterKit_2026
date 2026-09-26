<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\Redirect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Where should this path go?" — answered identically for both consumers.
 *
 * Requirement 7.5.
 *
 * Extracted from HandleRedirects because that middleware was the only implementation
 * and it runs on THIS host — which serves the API and the panel, and which real
 * visitors never touch. In the intended topology (blueprint §1) the public site is a
 * separate frontend deployment, so an editor who renamed a published slug and accepted
 * the 301 suggestion still watched the live site return 404: the table was correct and
 * nothing that served visitors ever read it.
 *
 * So the resolution rules now live in one service with two callers: the middleware,
 * which still protects any legacy traffic arriving here, and the Delivery API, which
 * is how the frontend gets the same answer. Two implementations of chain collapsing
 * would be worse than none — a frontend that stopped one hop short of the middleware's
 * destination would emit a 301 to a 301, which is exactly the crawler-budget problem
 * collapsing exists to solve.
 */
class RedirectResolver
{
    /**
     * Maximum hops to follow when one redirect's target is itself redirected.
     *
     * Chains happen legitimately — an article's slug changes twice — but each hop
     * costs the visitor a round trip and crawlers stop following after a few. So the
     * chain is RESOLVED to its final destination and the visitor gets a single
     * redirect, while the guard stops a cycle the loop check missed from spinning.
     */
    public const MAX_HOPS = 5;

    /**
     * The whole table, memoised for this instance on top of the shared cache.
     *
     * Two layers on purpose. The cache entry is what stops a database round trip per
     * request; the instance memo is what stops the export endpoint doing one cache read
     * per row while it collapses a page of 100 redirects.
     *
     * @var array<string, array{to: string, type: int}>|null
     */
    private ?array $map = null;

    /**
     * Resolve a path to its final destination, or null when nothing applies.
     *
     * Null covers three distinct cases the caller does not need to distinguish: the
     * path has no redirect, the redirect module is switched off, and the data contains
     * a cycle. All three mean "serve this path normally" — following a cycle would loop
     * the browser, and raising an error would take down a URL that might still resolve.
     *
     * @return array{from: string, to: string, status: int, hops: int}|null
     */
    public function resolve(string $path): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $from = Redirect::normalisePath($path);
        $map = $this->map();

        if (! isset($map[$from])) {
            return null;
        }

        $seen = [$from => true];
        $current = $from;

        /*
         * The FIRST hop's status code is what the caller is told, because that is the
         * relationship being described: if the original move was permanent, the whole
         * chain is permanent regardless of how later hops were recorded.
         */
        $status = $map[$from]['type'];

        for ($hop = 1; $hop <= self::MAX_HOPS; $hop++) {
            $target = Redirect::normalisePath($map[$current]['to']);

            if (! isset($map[$target])) {
                return ['from' => $from, 'to' => $target, 'status' => $status, 'hops' => $hop];
            }

            if (isset($seen[$target])) {
                return null;
            }

            $seen[$target] = true;
            $current = $target;
        }

        // Ran out of hops without terminating: treat as a loop rather than sending the
        // caller somewhere arbitrary mid-chain.
        return null;
    }

    /**
     * Whether the resolved destination should inherit the request's query string.
     *
     * False when the target defines its own, because overwriting a deliberate
     * `?sort=latest` with the visitor's `?sort=oldest` would silently defeat the
     * redirect's purpose. Exposed as a field on the API payload so a frontend applies
     * the same rule this host does rather than inventing its own.
     */
    public function preservesQueryString(string $target): bool
    {
        return ! str_contains($target, '?');
    }

    /**
     * Append the incoming query string to a destination, where that is right.
     *
     * Dropping it silently would break campaign tracking and paginated links across
     * every renamed URL, which is a wide and hard-to-attribute regression.
     */
    public function withQueryString(Request $request, string $target): string
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

        if ($query === '' || ! $this->preservesQueryString($target)) {
            return $target;
        }

        return $target.'?'.$query;
    }

    /**
     * Increment the hit counter without loading or saving a model.
     *
     * A single UPDATE rather than a read-modify-write, and deliberately NOT firing
     * model events: those would invalidate the cache the map was just read from and
     * make every redirect re-query the table.
     *
     * Counting is on the middleware's hot path today. The Delivery lookup counts too —
     * an editor pruning dead redirects needs the number to reflect reality, and once
     * the frontend is the thing honouring redirects, a table whose hit counts all sat
     * at zero would read as "nobody uses these, delete them".
     */
    public function recordHit(string $from): void
    {
        Redirect::query()
            ->where('from_path', Redirect::normalisePath($from))
            ->update([
                'hits' => DB::raw('hits + 1'),
                'last_hit_at' => now(),
            ]);
    }

    public function enabled(): bool
    {
        return (bool) config('cms.modules.redirect', true);
    }

    /**
     * from_path => [to, type], cached whole.
     *
     * The whole table as one entry rather than a query per lookup: redirects are read
     * on potentially every 404 and written rarely, so one cache entry busted on write
     * beats a database round trip on every miss. Redirect::booted() forgets this key on
     * save and delete.
     *
     * @return array<string, array{to: string, type: int}>
     */
    public function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        /** @var array<string, array{to: string, type: int}> $map */
        $map = Cache::rememberForever(Redirect::CACHE_KEY, function (): array {
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

        return $this->map = $map;
    }
}
