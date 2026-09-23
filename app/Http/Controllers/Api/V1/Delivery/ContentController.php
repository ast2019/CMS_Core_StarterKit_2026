<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

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
    public function __construct(private readonly DeliveryCache $cache) {}

    /**
     * List published news articles.
     *
     * Supports `locale`, `category`, `tag`, `per_page` and `page`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
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
                $paginator = $this->baseQuery($locale)
                    ->when(
                        $request->filled('category'),
                        fn (Builder $query) => $query->whereHas(
                            'categories',
                            fn (Builder $inner) => $inner->whereJsonContainsLocale(
                                'slug',
                                $locale,
                                (string) $request->query('category'),
                            ),
                        ),
                    )
                    ->when(
                        $request->filled('tag'),
                        fn (Builder $query) => $query->whereHas(
                            'tags',
                            fn (Builder $inner) => $inner->whereJsonContainsLocale(
                                'slug',
                                $locale,
                                (string) $request->query('tag'),
                            ),
                        ),
                    )
                    ->orderByDesc('publish_date')
                    ->paginate($perPage)
                    ->withQueryString();

                return ContentResource::collection($paginator);
            },
        );
    }

    /**
     * Fetch one published article by its per-locale slug.
     */
    public function show(Request $request, string $slug): ContentResource
    {
        $locale = $this->locale($request);

        $content = $this->resolveBySlug($locale, $slug);

        if ($content === null) {
            throw new NotFoundHttpException(
                "No published article found for slug [{$slug}] in locale [{$locale}] or the source locale.",
            );
        }

        return ContentResource::make($content);
    }

    /**
     * Resolve an article by slug, in the requested locale or via the source locale.
     *
     * Blueprint §2 requires fallback display when a translation is missing, and an
     * untranslated article has no slug in the target locale — so a
     * requested-locale-only lookup would make fallback unreachable for exactly the
     * records that need it.
     *
     * The source-locale attempt is a SECOND step, not a merged OR: a slug that
     * exists in the requested locale must always win, or a Persian slug could
     * shadow a different article that legitimately owns that slug in English.
     *
     * This does not create a duplicate-content problem, because the response marks
     * `is_fallback` and names the real locale, and the hreflang/canonical data tells
     * crawlers which URL is authoritative (Requirements 5.5, 7.2).
     */
    private function resolveBySlug(string $locale, string $slug): ?Content
    {
        $content = $this->baseQuery($locale)
            ->whereJsonContainsLocale('slug', $locale, $slug)
            ->first();

        if ($content !== null) {
            return $content;
        }

        $source = (string) config('cms.locales.source', 'fa');

        if ($locale === $source) {
            return null;
        }

        return $this->baseQuery($source)
            ->whereJsonContainsLocale('slug', $source, $slug)
            ->first();
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
    private function baseQuery(string $locale): Builder
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

    private function locale(Request $request): string
    {
        $locale = $request->attributes->get('cms_locale');

        return is_string($locale) ? $locale : app()->getLocale();
    }

    /**
     * Page size, bounded.
     *
     * An unbounded `per_page` is a denial-of-service vector on a public endpoint:
     * `?per_page=100000` would serialise the entire archive, with every relation, on
     * one request.
     */
    private function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', 15), 100));
    }
}
