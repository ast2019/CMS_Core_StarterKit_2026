<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\V1\Concerns\ResolvesLocale;
use App\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MediaAsset
 */
class MediaAssetResource extends JsonResource
{
    use ResolvesLocale;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = $this->locale($request);
        $media = $this->getFirstMedia('file');

        return [
            'id' => $this->id,
            'type' => $this->type,
            'alt_text' => $this->altTextFor($locale),
            'caption' => $this->translated($this->resource, 'caption', $locale),

            /*
             * Requirement 7.6 — intrinsic dimensions are part of the payload so the
             * frontend can set width/height and reserve space. Without them the
             * browser cannot avoid layout shift, and no amount of CSS on the client
             * recovers it.
             */
            'width' => $this->width,
            'height' => $this->height,

            'url' => $media?->getFullUrl(),
            'mime_type' => $this->mime_type,
            'size' => $this->size,

            /*
             * Conversions are exposed as a map so the frontend can build a srcset
             * without hardcoding this project's conversion names. RULE #9: every
             * URL here is same-origin, served from the local public disk.
             */
            'variants' => $media === null ? [] : $this->variants(),

            'video' => $this->when($this->isVideo(), fn (): array => [
                'duration_seconds' => $this->duration_seconds,
                'embed_url' => $this->external_embed_url,
                'thumbnail_url' => $this->getFirstMedia('video_thumbnail')?->getFullUrl(),
                // Decision D-6 — tells a consumer whether this video can legally
                // appear in the video sitemap yet.
                'sitemap_ready' => $this->hasVideoSitemapMetadata(),
            ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function variants(): array
    {
        $media = $this->getFirstMedia('file');

        if ($media === null) {
            return [];
        }

        $variants = [];

        foreach (array_keys((array) config('cms.media.conversions', [])) as $name) {
            foreach ([$name, "{$name}_webp"] as $conversion) {
                // A conversion may not exist yet: generation is queued, so a
                // just-uploaded image has none. Emitting a URL to a missing file
                // would give the frontend a broken <img> to render.
                if ($media->hasGeneratedConversion($conversion)) {
                    $variants[$conversion] = $media->getFullUrl($conversion);
                }
            }
        }

        return $variants;
    }
}
