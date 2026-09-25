<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Contracts\TracksTranslationStatus;
use App\Http\Resources\V1\Concerns\ResolvesLocale;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\Page;
use App\Models\Slide;
use Illuminate\Database\Eloquent\Model;
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
 * `link` is now RESOLVED for the requested locale rather than echoed from the column
 * (Requirement 5.2). A slide may point at a CMS record instead of a raw URL, in which
 * case the destination is built from the target's per-locale slug — so the same slide
 * returns /fa/news/x under `?locale=fa` and /en/news/y under `?locale=en`, and it
 * returns null when the target has been deleted, unpublished, or belongs to a module
 * this deployment has switched off (Requirement 1.1). The field name and type are
 * unchanged, so a frontend reading `link` keeps working and simply starts getting the
 * right URL.
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

        /*
         * Read ONCE, and from the eager-loaded mediaAssets collection: featuredImage()
         * now prefers the loaded relation (HasFeaturedImage::assetInRole), so this
         * costs no query on an endpoint that already loads the media. It used to cost
         * one per slide, which — with `should_preload` costing another — made the
         * homepage's slideshow payload two queries per slide on top of the set itself.
         */
        $asset = $this->featuredImage();
        $media = $asset?->getFirstMedia('file');
        $target = $this->resolvedTarget();

        return [
            'id' => $this->id,
            'title' => $this->translated($this->resource, 'title', $locale),
            'subtitle' => $this->translated($this->resource, 'subtitle', $locale),
            'cta_label' => $this->translated($this->resource, 'cta_label', $locale),
            'link' => $this->resolveUrl($locale),

            /*
             * Whether the destination is a CMS record or a hand-typed URL, and which
             * record. A frontend that renders slides needs this to decide between an
             * internal client-side transition and a full navigation — and without it
             * the only way to guess is to pattern-match the path, which breaks on an
             * external URL that happens to start with /fa.
             */
            'link_target' => $target === null ? null : [
                'type' => $this->deliveryTypeFor($target),
                'id' => $target->getKey(),
            ],

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

            'meta' => $this->slideMeta($locale, $target),
        ];
    }

    /**
     * Locale reporting that accounts for the linked target as well as the slide.
     *
     * Requirement 5.5 — no SILENT fallback. A slide's `link` is built from its
     * target's slug read WITH fallback, so an English slide can legitimately point at
     * /en/<persian-slug>; the sitemap and canonical layers refuse to advertise that URL
     * (Decision D-5). Reporting only the slide's own fields would hide the
     * disagreement, which is exactly what MenuItemResource already avoids.
     *
     * @return array<string, mixed>
     */
    private function slideMeta(string $locale, ?Model $target): array
    {
        $meta = $this->localeMeta($this->resource, $locale);

        $meta['is_fallback'] = $meta['is_fallback']
            || ($target !== null && $this->isFallback($target, $locale));

        $meta['fallback_locale'] = $meta['is_fallback'] ? $this->sourceLocale() : null;

        // A Slide has no translation lifecycle of its own, so localeMeta() reports null
        // here. When the destination is a record that DOES have one, that status is the
        // honest answer for the link.
        $meta['link_translation_status'] = $target instanceof TracksTranslationStatus
            ? $target->translationStatusFor($locale)->value
            : null;

        return $meta;
    }

    /**
     * The Delivery API's name for a linked record's type.
     *
     * The frontend consumes `pages/{slug}`, `news/{slug}`, `categories/{slug}` and
     * `galleries/{slug}`, so a fully-qualified PHP class name would be useless to it —
     * and leaking internal class names into a public payload invites consumers to
     * depend on them.
     */
    private function deliveryTypeFor(Model $target): string
    {
        return match ($target::class) {
            Content::class => 'news',
            Page::class => 'page',
            Category::class => 'category',
            Gallery::class => 'gallery',
            default => class_basename($target),
        };
    }
}
