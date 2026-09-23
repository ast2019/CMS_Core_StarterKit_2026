<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\V1\Concerns\ResolvesLocale;
use App\Models\Slide;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Homepage slideshow slide.
 *
 * Requirement 7.6 — the performance contract travels with the payload: explicit
 * dimensions to prevent layout shift, and a `should_preload` flag marking the first
 * slide. A frontend cannot work out which image to preload on its own without
 * guessing at ordering, and guessing wrong preloads the wrong asset and slows the
 * page it was meant to speed up.
 *
 * @mixin Slide
 */
class SlideResource extends JsonResource
{
    use ResolvesLocale;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = $this->locale($request);
        $asset = $this->featuredImage();
        $media = $asset?->getFirstMedia('file');

        return [
            'id' => $this->id,
            'title' => $this->translated($this->resource, 'title', $locale),
            'subtitle' => $this->translated($this->resource, 'subtitle', $locale),
            'cta_label' => $this->translated($this->resource, 'cta_label', $locale),
            'link' => $this->link,
            'position' => $this->position,

            'image' => $asset === null ? null : [
                'url' => $media?->getFullUrl(),
                // WebP first (blueprint §6 asks for WebP/AVIF), with the original
                // as the fallback source for a <picture> element.
                'webp_url' => $media !== null && $media->hasGeneratedConversion('large_webp')
                    ? $media->getFullUrl('large_webp')
                    : null,
                'alt_text' => $asset->altTextFor($locale),
                'width' => $this->image_width ?? $asset->width,
                'height' => $this->image_height ?? $asset->height,
            ],

            'should_preload' => $this->isFirstActive(),

            /*
             * Blueprint §6 forbids autoplay video in the slideshow. Stating it in
             * the payload rather than trusting each frontend to remember means the
             * constraint survives every site built on this Core.
             */
            'autoplay_video' => false,

            'meta' => $this->localeMeta($this->resource, $locale),
        ];
    }
}
