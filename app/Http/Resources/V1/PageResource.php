<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\V1\Concerns\ResolvesLocale;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Page
 */
class PageResource extends JsonResource
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

            // Named `blocks` to match the blueprint's §3 vocabulary, though it holds
            // the same TipTap document shape as an article body.
            'blocks' => $this->translated($this->resource, 'blocks', $locale),

            'system_key' => $this->system_key,
            'position' => $this->position,

            'featured_image' => $this->whenLoaded(
                'mediaAssets',
                fn () => $this->featuredImage() === null
                    ? null
                    : MediaAssetResource::make($this->featuredImage()),
            ),

            'seo' => [
                'meta_title' => $this->metaTitleFor($locale),
                // Not metaDescriptionFor(): a Page has no `excerpt`, and the trait's
                // fallback reaches for one unguarded. See ResolvesLocale.
                'meta_description' => $this->metaDescription($this->resource, $locale),
                'robots' => $this->robotsMetaFor($locale),
            ],

            'meta' => $this->localeMeta($this->resource, $locale),
        ];
    }
}
