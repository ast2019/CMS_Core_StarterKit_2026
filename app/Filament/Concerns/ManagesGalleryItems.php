<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\MediaRole;
use App\Filament\Schemas\MediaAssetPicker;
use App\Models\Gallery;
use App\Models\MediaAsset;

/**
 * Persists a gallery's item list for a Create/Edit page.
 *
 * Decision D-4 — the cover is the single `featured` attachment (handled by
 * ManagesFeaturedImage) and the items are a separate, uncapped `gallery`-role
 * collection. Requirement 3.1.
 *
 * This existed nowhere before. GalleryForm offered a `gallery_item_ids` multi
 * select, marked it `dehydrated(false)` like the cover field, and then no page
 * hook ever read it back — so an editor could pick twenty images, save, and get a
 * gallery with a cover and no contents, with no error to explain it. Adding an
 * inline uploader to that field without fixing this would just make it faster to
 * lose work.
 */
trait ManagesGalleryItems
{
    protected function syncGalleryItemsFromForm(): void
    {
        $record = $this->getRecord();

        // getRecord() is typed to Model, so narrow before using Gallery's API.
        if (! $record instanceof Gallery) {
            return;
        }

        $state = $this->form->getRawState()[MediaAssetPicker::GALLERY_ITEMS_FIELD] ?? null;

        /*
         * A null state means the field was never rendered (a page that does not
         * show it), which must not be read as "the editor emptied the gallery".
         * An empty ARRAY does mean that, and is honoured.
         */
        if (! is_array($state)) {
            return;
        }

        $desired = array_values(array_unique(array_map('intval', $state)));

        /*
         * Fetched once and keyed, rather than find()-ing inside the loops below:
         * a gallery of two hundred images would otherwise issue two hundred
         * queries per save. Filtering to what exists also stops a stale option
         * leaving a pivot row pointing at a deleted asset.
         */
        $assets = MediaAsset::query()->whereKey($desired)->get()->keyBy('id');

        $ordered = array_values(array_filter(
            $desired,
            static fn (int $id): bool => $assets->has($id),
        ));

        $current = array_map('intval', $record->items()->pluck('media_assets.id')->all());

        // No-op when the set AND the order are unchanged, so re-saving a gallery
        // does not rewrite twenty pivot rows — and with them the audit trail — on
        // every edit. Compared in order, because the order is the data here.
        if ($current === $ordered) {
            return;
        }

        /*
         * Detach then attach, rather than sync() on the relation: the position
         * column carries the editor's ordering, and attachMediaAsset() is the
         * single write path that assigns it (RULE #7's replacement logic lives
         * there too). Writing the pivot directly here would bypass both.
         */
        foreach ($record->items()->get() as $existing) {
            $record->detachMediaAsset($existing, MediaRole::Gallery);
        }

        foreach ($ordered as $position => $id) {
            $asset = $assets->get($id);

            if ($asset instanceof MediaAsset) {
                $record->attachMediaAsset($asset, MediaRole::Gallery, $position);
            }
        }
    }
}
