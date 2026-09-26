<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Contracts\TracksTranslationStatus;
use App\Http\Resources\V1\Concerns\ResolvesLocale;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One navigation item, resolved for a locale, with its subtree.
 *
 * Requirements 3.1, 5.5, 8.3.
 *
 * Every other entity on the Delivery API is shaped by a resource class; the menu
 * payload was assembled by hand inside SiteController, which is why it was the one
 * endpoint with no fallback reporting and an unbounded recursion. Both belong to
 * the payload, so both live here.
 *
 * @mixin MenuItem
 */
class MenuItemResource extends JsonResource
{
    use ResolvesLocale;

    /**
     * Levels still renderable from this item down, this one included.
     *
     * The depth cap is enforced in the payload as well as in the form, because the
     * form is not the only way rows get written and a cyclic or over-deep tree must
     * cost a bounded number of queries either way. MenuItem::treeEagerLoads() loads
     * exactly this many levels, so the traversal never lazy-loads.
     */
    protected int $remainingDepth = MenuItem::MAX_DEPTH;

    /**
     * Render a bounded forest of items, dropping the ones that resolve to nothing.
     *
     * @param  iterable<int, MenuItem>  $items
     * @return list<array<string, mixed>>
     */
    public static function tree(iterable $items, Request $request, ?int $remainingDepth = null): array
    {
        $depth = $remainingDepth ?? MenuItem::MAX_DEPTH;

        if ($depth < 1) {
            return [];
        }

        $tree = [];

        foreach ($items as $item) {
            $payload = self::make($item)->withRemainingDepth($depth)->toArray($request);

            /*
             * An item whose target is missing, unpublished, or owned by a disabled
             * module resolves to null. It is dropped here so the frontend never
             * renders a link into a 404 — unless it still has children, in which
             * case it is a section heading and the branch is worth keeping.
             */
            if ($payload['url'] === null && $payload['children'] === []) {
                continue;
            }

            $tree[] = $payload;
        }

        return $tree;
    }

    public function withRemainingDepth(int $depth): self
    {
        $this->remainingDepth = $depth;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = $this->locale($request);
        $target = $this->resolvedTarget();

        return [
            'id' => $this->id,
            'label' => $this->translated($this->resource, 'label', $locale),
            'url' => $this->resolveUrl($locale),
            'opens_in_new_tab' => $this->opens_in_new_tab,
            'children' => self::tree($this->children, $request, $this->remainingDepth - 1),

            /*
             * Requirement 5.5 — no silent fallback, stated in the same three fields
             * the content resources use. A menu item's URL is built from the
             * target's slug WITH fallback, so an item can legitimately point at
             * /en/<persian-slug>; the sitemap and canonical layers refuse to
             * advertise that URL (Decision D-5), and without these flags navigation
             * would be the one payload that hides the disagreement.
             */
            'meta' => $this->fallbackMeta($locale, $target),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackMeta(string $locale, ?Model $target): array
    {
        /*
         * Two sources of fallback, either of which makes the rendered item not
         * really this locale's: the item's own label (MenuItem has no translation
         * lifecycle, so existence of a label in the locale is the signal) and the
         * target's translation status (which does have a lifecycle, and an
         * unreviewed machine translation is not a translation — Decision D-5).
         */
        $isFallback = $this->isFallback($this->resource, $locale)
            || ($target !== null && $this->isFallback($target, $locale));

        return [
            'is_fallback' => $isFallback,
            'fallback_locale' => $isFallback ? $this->sourceLocale() : null,
            'translation_status' => $target instanceof TracksTranslationStatus
                ? $target->translationStatusFor($locale)->value
                : null,
        ];
    }
}
