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

            /*
             * Stated explicitly even though it is derivable from `system_key`, because
             * this is the flag that prevents a duplicate-content bug and a frontend
             * should not have to know the Core's magic string to find it.
             *
             * The homepage is addressed at /{locale}, not /{locale}/{slug} — that is
             * what UrlBuilder returns for it, so the canonical, the sitemap and every
             * menu link agree. But `pages/{slug}` still resolves it, deliberately: a
             * frontend built before the homepage concept existed keeps working. Such a
             * frontend must use this flag to redirect /{locale}/{slug} to /{locale} (or
             * at minimum canonicalise it there), or the same content will be reachable
             * at two URLs. See docs/redirects.md.
             */
            'is_homepage' => $this->isHomePage(),

            'position' => $this->position,

            'featured_image' => $this->whenLoaded(
                'mediaAssets',
                fn () => $this->featuredImage() === null
                    ? null
                    : MediaAssetResource::make($this->featuredImage()),
            ),

            'seo' => [
                'meta_title' => $this->metaTitleFor($locale),
                'meta_description' => $this->metaDescriptionFor($locale),
                'robots' => $this->robotsMetaFor($locale),
            ],

            'meta' => $this->localeMeta($this->resource, $locale),
        ];
    }
}
