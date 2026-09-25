<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\MediaRole;
use App\Http\Resources\V1\Concerns\ResolvesLocale;
use App\Models\Gallery;
use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * An image gallery for the Delivery API.
 *
 * Requirements 3.1, 5.5, 8.3. Read by Scramble to build the OpenAPI spec (RULE #3),
 * which is why the shape lives in a resource class rather than an inline array.
 *
 * Decision D-4 is visible in the payload: `cover` is the single `featured`
 * attachment (the one RULE #7 governs) and `items` are the uncapped `gallery`-role
 * attachments. They are separate keys because the cover may also BE one of the
 * items, and a frontend rendering a hero above a grid needs to know which is which
 * rather than inferring it from position.
 *
 * Both are derived from the ALREADY-LOADED mediaAssets collection rather than from
 * the model's items()/cover() relation calls. Those issue a query each, which is
 * affordable for a single article's featured image and is not affordable for the one
 * model whose media is deliberately uncapped.
 *
 * @mixin Gallery
 */
class GalleryResource extends JsonResource
{
    use ResolvesLocale;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = $this->locale($request);

        return [
            'id' => $this->id,
            'title' => $this->translated($this->resource, 'title', $locale),
            'slug' => $this->translated($this->resource, 'slug', $locale),
            'description' => $this->translated($this->resource, 'description', $locale),

            'publish_date' => $this->publish_date?->toIso8601String(),
            'status' => $this->status->value,

            'cover' => $this->whenLoaded('mediaAssets', function (): ?MediaAssetResource {
                $cover = $this->assetsInRole(MediaRole::Featured)->first();

                return $cover === null ? null : MediaAssetResource::make($cover);
            }),
            'items' => $this->whenLoaded(
                'mediaAssets',
                fn (): array => MediaAssetResource::collection($this->assetsInRole(MediaRole::Gallery))
                    ->resolve($request),
            ),
            'item_count' => $this->whenLoaded(
                'mediaAssets',
                fn (): int => $this->assetsInRole(MediaRole::Gallery)->count(),
            ),

            'seo' => [
                'meta_title' => $this->metaTitleFor($locale),
                'meta_description' => $this->metaDescriptionFor($locale),
                // Unreviewed translations are noindex automatically, so a frontend
                // that renders this verbatim cannot leak one to search.
                'robots' => $this->robotsMetaFor($locale),
            ],

            'meta' => $this->localeMeta($this->resource, $locale),
        ];
    }

    /**
     * Attachments in one role, read from the loaded collection.
     *
     * Ordering comes from the eager load (mediaAssets orders by the pivot position),
     * so the editor's chosen item order survives into the payload — filtering a
     * loaded collection preserves order, unlike a fresh query without the same
     * ordering clause.
     *
     * @return Collection<int, MediaAsset>
     */
    private function assetsInRole(MediaRole $role): Collection
    {
        /** @var Collection<int, MediaAsset> $assets */
        $assets = $this->mediaAssets;

        return $assets
            ->filter(function (MediaAsset $asset) use ($role): bool {
                // The pivot arrives as a relation on each hydrated asset, so it is
                // read as one: `$asset->pivot` is a dynamic property that static
                // analysis cannot see on the MediaAsset model.
                $pivot = $asset->relationLoaded('pivot') ? $asset->getRelation('pivot') : null;

                return $pivot instanceof Model && $pivot->getAttribute('role') === $role->value;
            })
            ->values();
    }
}
