<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Api\V1\Delivery\Concerns\ResolvesDeliveryRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RedirectResource;
use App\Models\Redirect;
use App\Services\Api\DeliveryCache;
use App\Services\Content\RedirectResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Redirects, for the frontend that actually receives the stale traffic.
 *
 * Requirements 1.1, 7.5, 8.1, 8.3, 8.4, 8.7.
 *
 * WHY this endpoint had to exist. The redirect engine was complete and unreachable:
 * slug changes generated suggestions, the panel stored them, HandleRedirects collapsed
 * chains and preserved query strings — and it all ran on THIS host, which serves the
 * API and the panel. Real visitors hit the old URL on the separate frontend deployment
 * (blueprint §1), which never passes through that middleware, and the middleware
 * excludes `api/*` anyway so the frontend could not even piggyback on it by proxying.
 * The net effect was that an editor renamed a published slug, accepted the 301, and the
 * live site kept returning 404. A redirect table nobody queries is decorative.
 *
 * TWO endpoints, because frontends resolve this in two genuinely different ways and
 * either one alone leaves a common architecture unserved:
 *
 *  - `resolve` is a single-path lookup for a server-rendered or edge-middleware
 *    frontend (Next.js middleware, an SSR 404 handler): on a miss it asks this host
 *    once and emits the redirect itself.
 *  - `index` is the whole table, paginated, for a build-time or statically-exported
 *    frontend that compiles redirects into its own config (`next.config.js` redirects,
 *    a Netlify `_redirects` file, an nginx map). Such a site has no request-time hook
 *    to call `resolve` from.
 *
 * Both return the SAME shape, already collapsed by RedirectResolver, so the two hosts
 * cannot disagree about where a chain ends.
 *
 * See docs/redirects.md for the frontend's side of the contract.
 */
class RedirectController extends Controller
{
    use ResolvesDeliveryRequest;

    public function __construct(
        private readonly RedirectResolver $redirects,
        private readonly DeliveryCache $cache,
    ) {}

    /**
     * Resolve one path to its final destination.
     *
     * 404 when no redirect applies, which is the honest answer and the one the caller
     * can act on directly: the frontend renders its own not-found page (the branded one
     * from `not-found-page`, Requirement 3.8). A 200 carrying `{"to": null}` would make
     * every consumer write the same null check and would cache "no redirect" and "a
     * redirect to null" identically.
     *
     * Not wrapped in DeliveryCache. RedirectResolver caches the whole table as one
     * entry, so a lookup is already an in-memory array read — while caching PER PATH
     * would create one cache entry for every URL anyone ever asks about, including every
     * nonsense path a crawler tries. That is unbounded key growth driven by untrusted
     * input, on the exact endpoint a 404 flood would target.
     */
    public function resolve(Request $request): JsonResponse
    {
        $this->ensureModuleEnabled('redirect');

        $from = (string) $request->query('from', '');

        if (trim($from) === '') {
            return new JsonResponse([
                'message' => 'A [from] path is required, e.g. ?from=/fa/news/old-slug.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        /*
         * A caller will pass the URL it received, query string and all. Matching
         * ignores it — `redirects.from_path` stores paths only — and the response says
         * via `preserve_query` whether the caller should re-attach it. Doing the
         * re-attachment here instead would mean echoing a visitor's arbitrary query
         * string back inside a cached-looking payload.
         */
        $resolved = $this->redirects->resolve($this->pathOnly($from));

        if ($resolved === null) {
            return new JsonResponse([
                'message' => 'No redirect is configured for this path.',
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        $row = Redirect::query()->where('from_path', $resolved['from'])->first();

        if ($row === null) {
            // The map said there is a row and the table disagrees: a delete that landed
            // between the cache read and this query. Treat it as no redirect rather than
            // fabricating a payload.
            return new JsonResponse([
                'message' => 'No redirect is configured for this path.',
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        /*
         * Counted here as well as in the middleware. Once the frontend is the thing
         * honouring redirects, this is where nearly all real traffic arrives — and an
         * editor pruning the table on evidence would otherwise see every hit count sit
         * at zero and conclude the rows are dead.
         */
        $this->redirects->recordHit($resolved['from']);

        return new JsonResponse([
            'data' => RedirectResource::make($row)
                ->collapsedTo($resolved['to'], $resolved['status'], $resolved['hops'])
                ->toArray($request),
        ]);
    }

    /**
     * The whole redirect table, paginated and collapsed.
     *
     * Paginated rather than returned whole: the table grows by one row per slug change
     * per locale and has no natural ceiling, so an unbounded export is the same
     * denial-of-service shape `per_page` exists to prevent elsewhere. A build-time
     * consumer walks the pages once, which is cheap when it happens at deploy time.
     *
     * Ordered by id so pagination is stable — ordering by `from_path` would reshuffle
     * pages as new redirects are inserted, and a consumer walking page by page would
     * silently miss rows.
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureModuleEnabled('redirect');

        $perPage = $this->perPage($request, default: 100, max: 500);
        $page = max(1, $request->integer('page', 1));

        /*
         * Cached under TAG_REDIRECT, which DeliveryCacheObserver busts on any redirect
         * write. Cacheable per page because the inputs are bounded (page number and a
         * clamped page size) — unlike `resolve`, whose input is an arbitrary path.
         *
         * Locale is part of the key by DeliveryCache convention even though redirects
         * are locale-agnostic (the locale is already inside the stored path). Deviating
         * would mean a second key-building rule to remember, and the duplication is two
         * extra entries.
         */
        $payload = $this->cache->remember(
            $this->cache->key('redirects.index', $this->locale($request), [
                'page' => $page,
                'per_page' => $perPage,
            ]),
            [DeliveryCache::TAG_REDIRECT],
            function () use ($perPage, $page, $request): array {
                $paginator = Redirect::query()
                    ->orderBy('id')
                    ->paginate(perPage: $perPage, page: $page);

                $data = [];

                foreach ($paginator->items() as $redirect) {
                    $resolved = $this->redirects->resolve($redirect->from_path);

                    /*
                     * Null means this row is part of a cycle. It is OMITTED rather than
                     * exported with its raw `to_path`: exporting it would hand the
                     * frontend a rule that loops the browser, which is worse than the
                     * old URL 404ing. The middleware makes the same judgement.
                     */
                    if ($resolved === null) {
                        continue;
                    }

                    $data[] = RedirectResource::make($redirect)
                        ->collapsedTo($resolved['to'], $resolved['status'], $resolved['hops'])
                        ->toArray($request);
                }

                return [
                    'data' => $data,
                    'meta' => [
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                    ],
                ];
            },
        );

        return new JsonResponse($payload);
    }

    /**
     * Strip a query string or fragment a caller passed along with the path.
     *
     * Redirect::normalisePath() already strips a scheme and host — editors paste full
     * URLs — but it keeps everything after the path, so `?utm_source=x` would be part of
     * the lookup key and match nothing.
     */
    private function pathOnly(string $from): string
    {
        $path = (string) preg_replace('/[?#].*$/', '', $from);

        return $path === '' ? '/' : $path;
    }
}
