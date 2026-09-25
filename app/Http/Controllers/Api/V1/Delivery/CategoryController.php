<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Api\V1\Delivery\Concerns\ResolvesDeliveryRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CategoryResource;
use App\Http\Resources\V1\ContentResource;
use App\Models\Category;
use App\Services\Api\DeliveryCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public, read-only category archives.
 *
 * Requirements 3.1, 8.1, 8.3, 8.4.
 *
 * WHY this endpoint had to exist: a Category is a linkable menu target and appears in
 * the sitemaps, so the API already advertised /fa/category-slug while a frontend had
 * no way to render it. `news?category=slug` returns the ARTICLES but nothing about
 * the category itself — no name for the heading, no description, no children for the
 * sub-navigation, and no way to tell a slug that belongs to an empty category from
 * one that does not exist at all.
 *
 * WHY the archive and the category come back together: rendering the page needs
 * both, and two round trips would double the latency of every archive view for a
 * payload the server can assemble in one query pair.
 *
 * A Category has NO publish status — deliberately, it is taxonomy, not content — so
 * there is nothing to gate on for the category itself. Its LISTING is gated the same
 * way every other public content query is, through Content::live().
 */
class CategoryController extends Controller
{
    use ResolvesDeliveryRequest;

    public function __construct(private readonly DeliveryCache $cache) {}

    /**
     * A category and its page of live articles.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $this->ensureModuleEnabled('category');

        $locale = $this->locale($request);
        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));

        /*
         * The rendered ARRAY is cached rather than the models: caching models would
         * re-run every translation lookup and relation accessor on each hit, which is
         * most of the cost the cache exists to avoid.
         *
         * `page` is part of the key. Leaving it out would serve page 1 for every
         * page of the archive until the TTL expired — the pagination bug that looks
         * like "the archive only has fifteen articles".
         */
        $payload = $this->cache->remember(
            $this->cache->key('category.show', $locale, [
                'slug' => $slug,
                'per_page' => $perPage,
                'page' => $page,
            ]),
            [DeliveryCache::TAG_TAXONOMY, DeliveryCache::TAG_CONTENT],
            function () use ($request, $locale, $slug, $perPage): array {
                $category = $this->resolveBySlug(
                    fn (): Builder => Category::query()->with(['children', 'translationStates']),
                    $locale,
                    $slug,
                );

                if ($category === null) {
                    throw new NotFoundHttpException(
                        "No category found for slug [{$slug}] in locale [{$locale}] or the source locale.",
                    );
                }

                $paginator = $category->contents()
                    // The single publish gate (Requirement 3.6): an archive must not
                    // leak a draft or an embargoed article any more than the news
                    // listing may.
                    ->live()
                    ->with([
                        'author:id,name',
                        'primaryCategory',
                        'categories',
                        'tags',
                        'mediaAssets',
                        'translationStates',
                    ])
                    ->orderByDesc('publish_date')
                    ->paginate($perPage)
                    ->withQueryString();

                return [
                    'data' => [
                        'category' => CategoryResource::make($category)->resolve($request),
                        'contents' => ContentResource::collection($paginator->items())->resolve($request),
                    ],
                    'meta' => [
                        'locale' => $locale,
                        'total' => $paginator->total(),
                        'per_page' => $paginator->perPage(),
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                    ],
                ];
            },
        );

        return new JsonResponse($payload);
    }
}
