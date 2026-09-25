<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Api\V1\Delivery\Concerns\ResolvesDeliveryRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PageResource;
use App\Models\Page;
use App\Services\Api\DeliveryCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public, read-only static pages.
 *
 * Requirements 3.1, 8.1, 8.3, 8.4.
 *
 * WHY this endpoint had to exist: a Page is a linkable menu target AND a sitemap
 * entry, so the API was already telling frontends that /fa/about exists — through
 * `menus/{key}` and through the page sitemap — while offering no way to fetch it.
 * The only reachable page was the branded 404 (`not-found-page`), by system key.
 */
class PageController extends Controller
{
    use ResolvesDeliveryRequest;

    public function __construct(private readonly DeliveryCache $cache) {}

    /**
     * Fetch one published page by its per-locale slug.
     */
    public function show(Request $request, string $slug): PageResource
    {
        $this->ensureModuleEnabled('page');

        $locale = $this->locale($request);

        /*
         * The cached value is the resource, matching how the news listing caches. The
         * 404 is thrown from INSIDE the callback, so a miss is never cached: a page
         * published a second after someone requested it must not stay missing for
         * the rest of the TTL.
         */
        return $this->cache->remember(
            $this->cache->key('page.show', $locale, ['slug' => $slug]),
            [DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_MEDIA],
            function () use ($locale, $slug): PageResource {
                $page = $this->resolveBySlug(
                    fn (): Builder => $this->baseQuery(),
                    $locale,
                    $slug,
                );

                if ($page === null) {
                    throw new NotFoundHttpException(
                        "No published page found for slug [{$slug}] in locale [{$locale}] or the source locale.",
                    );
                }

                return PageResource::make($page);
            },
        );
    }

    /**
     * Base query for public reads.
     *
     * `live()` is the single gate: published status AND publish_date in the past
     * (Requirement 3.6), so a draft "About" page and one scheduled for next week are
     * both unreachable here rather than only hidden from the sitemap.
     *
     * @return Builder<Page>
     */
    private function baseQuery(): Builder
    {
        return Page::query()
            ->live()
            ->with(['mediaAssets', 'translationStates']);
    }
}
