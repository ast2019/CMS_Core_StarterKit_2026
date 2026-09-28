<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Api\V1\Delivery\Concerns\ResolvesDeliveryRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ContentResource;
use App\Models\Content;
use App\Services\Api\DeliveryCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public, read-only news endpoints.
 *
 * Requirements 8.1, 8.3, 8.4.
 */
class ContentController extends Controller
{
    /*
     * Locale resolution, the per_page bound and the slug-with-fallback lookup moved
     * into a shared concern when the page/category/gallery endpoints were added.
     * They were about to exist in four copies, and these are exactly the rules that
     * must not drift: a forgotten per_page bound is a denial-of-service vector, and
     * a fallback lookup implemented differently per content type is a locale that
     * 404s for pages while working for articles.
     */
    use ResolvesDeliveryRequest;

    public function __construct(private readonly DeliveryCache $cache) {}

    /**
     * List published news articles.
     *
     * Supports `locale`, `category`, `tag`, `per_page` and `page`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensureModuleEnabled('content');

        $locale = $this->locale($request);
        $perPage = $this->perPage($request);

        $filters = [
            'category' => $request->query('category'),
            'tag' => $request->query('tag'),
            'per_page' => $perPage,
            'page' => $request->integer('page', 1),
        ];

        /*
         * The cached value is the resource collection's rendered array rather than
         * the Eloquent models. Caching models would re-run every relation accessor
         * and every translation lookup on each hit, which is most of the cost the
         * cache exists to avoid.
         */
        return $this->cache->remember(
            $this->cache->key('content.index', $locale, $filters),
            [DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_TAXONOMY],
            function () use ($request, $locale, $perPage): AnonymousResourceCollection {
                $paginator = $this->baseQuery()
                    ->when(
                        $request->filled('category'),
                        fn (Builder $query) => $query->whereHas(
                            'categories',
                            // Every spelling of the slug, so ?category=…-۱۴۰۳ finds …-1403.
                            fn (Builder $inner) => $inner->whereIn(
                                "slug->{$locale}",
                                $this->slugCandidates((string) $request->query('category'), $locale),
                            ),
                        ),
                    )
                    ->when(
                        $request->filled('tag'),
                        fn (Builder $query) => $query->whereHas(
                            'tags',
                            // Every spelling of the slug, so ?tag=…-۱۴۰۳ finds …-1403.
                            fn (Builder $inner) => $inner->whereIn(
                                "slug->{$locale}",
                                $this->slugCandidates((string) $request->query('tag'), $locale),
                            ),
                        ),
                    )
                    ->orderByDesc('publish_date')
                    ->paginate($perPage)
                    /*
                     * Only what the cache key is made of, never withQueryString().
                     * This body is cached and served to everyone with the same key, so
                     * echoing the request's own query string put the FIRST caller's
                     * arbitrary parameters (?utm_source=…&x=…) into every later
                     * caller's next/prev links for the whole TTL. The locale is written
                     * out too, so a link works the same whether the first request
                     * chose it by ?locale= or by Accept-Language.
                     */
                    ->appends(array_filter([
                        'locale' => $locale,
                        'category' => $request->filled('category') ? (string) $request->query('category') : null,
                        'tag' => $request->filled('tag') ? (string) $request->query('tag') : null,
                        'per_page' => $perPage,
                    ], fn (mixed $value): bool => $value !== null));

                return ContentResource::collection($paginator);
            },
        );
    }

    /**
     * Fetch one published article by its per-locale slug.
     */
    public function show(Request $request, string $slug): ContentResource
    {
        $this->ensureModuleEnabled('content');

        $locale = $this->locale($request);

        $content = $this->resolveBySlug(fn (): Builder => $this->baseQuery(), $locale, $slug);

        if ($content === null) {
            throw new NotFoundHttpException(
                "No published article found for slug [{$slug}] in locale [{$locale}] or the source locale.",
            );
        }

        return ContentResource::make($content);
    }

    /**
     * Base query for public reads.
     *
     * `live()` is the single gate: published status AND publish_date in the past
     * (Requirement 3.6). Every public query goes through here so scheduling cannot
     * be forgotten in one endpoint and leak an embargoed article.
     *
     * @return Builder<Content>
     */
    private function baseQuery(): Builder
    {
        return Content::query()
            ->live()
            ->with([
                'author:id,name',
                'primaryCategory',
                'categories',
                'tags',
                'mediaAssets',
                'translationStates',
            ]);
    }
}
