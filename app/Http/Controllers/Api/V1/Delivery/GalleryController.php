<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Api\V1\Delivery\Concerns\ResolvesDeliveryRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\GalleryResource;
use App\Models\Gallery;
use App\Services\Api\DeliveryCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public, read-only galleries.
 *
 * Requirements 3.1, 8.1, 8.3, 8.4. Decision D-4 — cover and items are distinct.
 *
 * WHY this endpoint had to exist: a Gallery is a linkable menu target and a sitemap
 * entry (its images feed the image sitemap), so a menu item could hand a frontend
 * /fa/gallery-slug with nothing on the API able to answer for it. A gallery_embed
 * custom block inside an article body has the same problem from the other
 * direction: it carries only a gallery_id, and the frontend needs a way to resolve
 * that into images.
 */
class GalleryController extends Controller
{
    use ResolvesDeliveryRequest;

    public function __construct(private readonly DeliveryCache $cache) {}

    /**
     * Fetch one published gallery, with its items, by its per-locale slug.
     */
    public function show(Request $request, string $slug): GalleryResource
    {
        $this->ensureModuleEnabled('gallery');

        $locale = $this->locale($request);

        return $this->cache->remember(
            $this->cache->key('gallery.show', $locale, ['slug' => $slug]),
            // TAG_MEDIA as well as TAG_CONTENT: a gallery payload is mostly media, so
            // editing one asset's alt text has to drop it. DeliveryCacheObserver
            // already invalidates both for MediaAsset writes.
            [DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_MEDIA],
            function () use ($locale, $slug): GalleryResource {
                $gallery = $this->resolveBySlug(
                    fn (): Builder => $this->baseQuery(),
                    $locale,
                    $slug,
                );

                if ($gallery === null) {
                    throw new NotFoundHttpException(
                        "No published gallery found for slug [{$slug}] in locale [{$locale}] or the source locale.",
                    );
                }

                return GalleryResource::make($gallery);
            },
        );
    }

    /**
     * Base query for public reads.
     *
     * mediaAssets is eager-loaded once and the resource splits it into cover and
     * items by pivot role; loading them as two relations would query the same table
     * twice for the same rows.
     *
     * @return Builder<Gallery>
     */
    private function baseQuery(): Builder
    {
        return Gallery::query()
            // Requirement 3.6 — a draft or scheduled gallery is not public.
            ->live()
            ->with(['mediaAssets', 'translationStates']);
    }
}
