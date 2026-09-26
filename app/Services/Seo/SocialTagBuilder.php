<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Contracts\HasFeaturedMedia;
use App\Contracts\HasSeoMetadata;
use App\Models\Content;
use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Model;

/**
 * Open Graph and Twitter Card tags for a record, per locale (Requirement 7.1).
 *
 * ---------------------------------------------------------------------------
 * Why this left the controller
 * ---------------------------------------------------------------------------
 * openGraph() was a private method on the Delivery SeoController, which made it
 * reachable from exactly one endpoint — the per-article one. Three things were
 * wrong with that, and all three are the same thing:
 *
 *  - There were no Twitter tags at all, so every shared link on X rendered from
 *    whatever that crawler could infer. Adding them to the controller would have
 *    doubled a private method nothing else could call.
 *  - Pages, galleries and categories gained Delivery endpoints in the meantime and
 *    have exactly the same social-card problem, with no way to reuse the answer.
 *  - The values were always DERIVED from the meta fields, with no override, because
 *    a private controller method is not somewhere a feature gets added.
 *
 * So the tags are built here, generically over any SEO-bearing model, and the
 * controller composes them. It is wired into the article endpoint only for now:
 * changing one Delivery payload is a documented API change (RULE #3), and changing
 * four in one commit without the frontend asking for them is churn.
 *
 * ---------------------------------------------------------------------------
 * The one judgement call: which Twitter card type
 * ---------------------------------------------------------------------------
 * `summary_large_image` is claimed ONLY when this Core can see that the image
 * actually meets the minimum X renders it at (300x157). An asset whose width and
 * height columns are null — true of anything uploaded before dimension capture, and
 * of anything whose dimensions a backfill has not reached — gets `summary` instead.
 *
 * That is the conservative direction on purpose, and it is the same rule
 * SchemaBuilder states as "omit rather than guess": claiming a large card for an
 * image that turns out to be 150px wide produces a visibly broken share card, while
 * `summary` on a large image merely under-sells it. Records whose dimensions are
 * backfilled later start reporting the large card with no further change here.
 */
class SocialTagBuilder
{
    /**
     * X/Twitter's documented minimum for a large summary card.
     *
     * Constants rather than config: they are that platform's published rendering
     * rule, not a preference of this site, and a deployment lowering them would not
     * change how the card renders — only whether this Core lies about it.
     */
    private const LARGE_CARD_MIN_WIDTH = 300;

    private const LARGE_CARD_MIN_HEIGHT = 157;

    public const CARD_SUMMARY = 'summary';

    public const CARD_SUMMARY_LARGE_IMAGE = 'summary_large_image';

    public function __construct(private readonly UrlBuilder $urls) {}

    /**
     * @return array<string, mixed>
     */
    public function openGraph(Model&HasSeoMetadata $record, string $locale): array
    {
        $image = $this->shareImage($record);

        $tags = [
            'og:type' => $record instanceof Content ? 'article' : 'website',
            /*
             * ogTitleFor()/ogDescriptionFor() rather than metaTitleFor(): they return
             * the per-record override when the editor set one and fall back to the meta
             * value otherwise, so a blank override serves byte-for-byte what this
             * endpoint served before the columns existed.
             */
            'og:title' => $record->ogTitleFor($locale),
            'og:description' => $record->ogDescriptionFor($locale),
            'og:url' => $this->urls->canonicalFor($record, $locale),
            'og:locale' => $locale,
            'og:image' => $this->imageUrl($image),
            'og:image:alt' => $image?->altTextFor($locale),
        ];

        if ($record instanceof Content) {
            // article:* is only meaningful for og:type=article, so it travels with the
            // condition that sets it rather than being emitted as two null keys on
            // everything else.
            $tags['article:published_time'] = $record->publish_date?->toIso8601String();
            $tags['article:modified_time'] = $record->updated_at?->toIso8601String();
        }

        return $tags;
    }

    /**
     * @return array<string, mixed>
     */
    public function twitter(Model&HasSeoMetadata $record, string $locale): array
    {
        $image = $this->shareImage($record);

        return [
            'twitter:card' => $this->cardType($image),
            /*
             * The same resolved title and description as Open Graph, deliberately.
             * X falls back to the og:* tags when the twitter:* ones are absent, so
             * emitting DIFFERENT text here would be a second thing to keep in step
             * with no editorial control offering it — a third and fourth override
             * field for one more platform is the kind of form nobody fills in
             * correctly.
             */
            'twitter:title' => $record->ogTitleFor($locale),
            'twitter:description' => $record->ogDescriptionFor($locale),
            'twitter:image' => $this->imageUrl($image),
            /*
             * Alt text is mandatory on every asset (Requirement 2.7), which is what
             * makes this tag free to emit here and worth emitting: it is the only
             * accessible description a screen reader gets for a shared card.
             */
            'twitter:image:alt' => $image?->altTextFor($locale),
        ];
    }

    /**
     * The asset a share card should use.
     *
     * socialShareImage() is the model's own answer: the explicit og_image attachment
     * if there is one, else the featured image. Falling back is what stops a shared
     * link rendering with no preview merely because a second field was left blank.
     */
    private function shareImage(Model&HasSeoMetadata $record): ?MediaAsset
    {
        /*
         * The contract, not method_exists(): a Category is SEO-bearing but carries no
         * media at all (it does not use HasFeaturedImage), and stating that in a type
         * is what HasFeaturedMedia exists for — its own docblock says so.
         */
        return $record instanceof HasFeaturedMedia
            ? $record->socialShareImage()
            : null;
    }

    private function imageUrl(?MediaAsset $image): ?string
    {
        return $image?->getFirstMedia('file')?->getFullUrl();
    }

    /**
     * Which card X should render.
     */
    private function cardType(?MediaAsset $image): string
    {
        if ($image === null || ! $image->isImage()) {
            return self::CARD_SUMMARY;
        }

        if ($image->width === null || $image->height === null) {
            // Dimensions unknown, so the large card cannot be honestly claimed.
            return self::CARD_SUMMARY;
        }

        return $image->width >= self::LARGE_CARD_MIN_WIDTH && $image->height >= self::LARGE_CARD_MIN_HEIGHT
            ? self::CARD_SUMMARY_LARGE_IMAGE
            : self::CARD_SUMMARY;
    }
}
