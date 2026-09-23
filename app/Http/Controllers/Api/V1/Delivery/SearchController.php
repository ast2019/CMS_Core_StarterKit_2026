<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ContentResource;
use App\Models\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale-aware, paginated full-text search.
 *
 * Requirements 6.2, 6.3, 6.5.
 */
class SearchController extends Controller
{
    private const MIN_QUERY_LENGTH = 2;

    public function __invoke(Request $request): JsonResponse
    {
        if (! (bool) config('cms.modules.search', true)) {
            // Requirement 1.1 — a disabled module serves nothing.
            return new JsonResponse(['message' => 'Search is not enabled.'], Response::HTTP_NOT_FOUND);
        }

        $locale = $this->locale($request);
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            /*
             * A one-character query matches most of the corpus and costs the engine a
             * near-full scan, so it is rejected rather than served. 422 rather than an
             * empty 200: the caller sent something the API cannot usefully answer, and
             * an empty result set would read as "no matches".
             */
            return new JsonResponse([
                'message' => 'The search term is too short.',
                'errors' => ['q' => ['Provide at least '.self::MIN_QUERY_LENGTH.' characters.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $perPage = max(1, min($request->integer('per_page', 15), 50));

        try {
            /*
             * forSearchLocale() picks the locale's index (Requirement 6.2). Without it
             * the query would hit whichever index the ambient locale happened to
             * resolve to, which is the bug a single shared index would have had anyway.
             */
            $paginator = Content::query()
                ->getModel()
                ->forSearchLocale($locale)
                ->search($term)
                ->query(fn ($query) => $query->with([
                    'author:id,name',
                    'primaryCategory',
                    'categories',
                    'tags',
                    'mediaAssets',
                    'translationStates',
                ]))
                ->paginate($perPage);
        } catch (\Throwable $exception) {
            /*
             * Requirement 6.5 — an unreachable search service returns a structured 503,
             * not an unhandled exception. Search is a degraded-mode feature: the rest of
             * the site works without it, so a search outage must not surface as a 500
             * that looks like the whole API is down.
             */
            Log::warning('Search backend unavailable.', [
                'locale' => $locale,
                'error' => $exception->getMessage(),
            ]);

            return new JsonResponse([
                'message' => 'Search is temporarily unavailable.',
            ], Response::HTTP_SERVICE_UNAVAILABLE)
                ->withHeaders(['Retry-After' => '60', 'Cache-Control' => 'no-store']);
        }

        return new JsonResponse([
            'data' => ContentResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'locale' => $locale,
                'query' => $term,
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    private function locale(Request $request): string
    {
        $locale = $request->attributes->get('cms_locale');

        return is_string($locale) ? $locale : app()->getLocale();
    }
}
