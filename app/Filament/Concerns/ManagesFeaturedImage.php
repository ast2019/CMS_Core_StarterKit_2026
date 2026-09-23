<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Contracts\HasFeaturedMedia;
use App\Enums\MediaRole;
use App\Models\MediaAsset;

/**
 * Persists the featured-image selection for a Create/Edit page.
 *
 * RULE #7 — Requirements 3.2, 3.3.
 *
 * The form field is `dehydrated(false)` because the attachment is not a column on
 * the record: it is a row in the polymorphic `media_attachments` pivot
 * (Decision D-3). So it has to be applied after the record exists, which is why
 * this runs in afterCreate/afterSave rather than as a normal attribute.
 */
trait ManagesFeaturedImage
{
    protected function afterCreate(): void
    {
        $this->syncFeaturedImageFromForm();
    }

    protected function afterSave(): void
    {
        $this->syncFeaturedImageFromForm();
    }

    protected function syncFeaturedImageFromForm(): void
    {
        $assetId = $this->form->getRawState()['featured_media_asset_id'] ?? null;

        if (blank($assetId)) {
            return;
        }

        $asset = MediaAsset::find($assetId);

        if ($asset === null) {
            return;
        }

        $record = $this->getRecord();

        /*
         * Filament's getRecord() is typed to Model, so the featured-image methods
         * are invisible to static analysis here. The instanceof check is not
         * defensive padding — it is what makes this trait safe to add to a page
         * whose model does not carry HasFeaturedImage, which would otherwise be a
         * runtime BadMethodCallException on save.
         */
        if (! $record instanceof HasFeaturedMedia) {
            return;
        }

        // No-op when unchanged, so re-saving an article does not churn the pivot
        // (and with it the audit trail) on every edit.
        if ($record->featuredImage()?->getKey() === $asset->getKey()) {
            return;
        }

        // setFeaturedImage() detaches any existing featured attachment first, so
        // "exactly one" holds without this trait needing to know about it.
        $record->setFeaturedImage($asset);
    }

    /**
     * Roles this page manages. Kept explicit so a future page adding gallery or
     * og_image handling does not silently inherit featured-only behaviour.
     *
     * @return list<MediaRole>
     */
    protected function managedMediaRoles(): array
    {
        return [MediaRole::Featured];
    }
}
