<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\MediaRole;
use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * RULE #7 — FEATURED IMAGE: "shared trait on every content-bearing model."
 *
 * Applied to Content, Page, Gallery and Slide. Blueprint §12.7,
 * Requirements 3.2, 3.3.
 *
 * ---------------------------------------------------------------------------
 * Decision D-3, and a refinement of it
 * ---------------------------------------------------------------------------
 * The blueprint (§9) asked for a `content_media` pivot carrying a `role`, while
 * §3 made Media Asset a first-class reusable library. Spatie Media Library
 * cannot satisfy both: its `media` rows are polymorphic one-to-many and belong
 * to exactly one owner, so attaching the same file to two articles would mean
 * two copies on disk and two independent alt_text values to keep in sync.
 *
 * Hence the two-tier model: `MediaAsset` is the library entity and owns the
 * Spatie media (original + conversions), and an attachment table links assets
 * to content with a role.
 *
 * The refinement: the design named that table `content_media_asset`, which only
 * serves Content. Featured images are required on four models, so a
 * model-specific pivot would mean four near-identical tables and four copies of
 * this logic. The table is therefore polymorphic — `media_attachments`, keyed by
 * (attachable_type, attachable_id, media_asset_id, role).
 */
trait HasFeaturedImage
{
    /**
     * Every media asset attached to this model, in any role.
     *
     * @return MorphToMany<MediaAsset, $this>
     */
    public function mediaAssets(): MorphToMany
    {
        return $this->morphToMany(MediaAsset::class, 'attachable', 'media_attachments')
            ->withPivot(['role', 'position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * @return MorphToMany<MediaAsset, $this>
     */
    public function mediaAssetsInRole(MediaRole $role): MorphToMany
    {
        return $this->mediaAssets()->wherePivot('role', $role->value);
    }

    public function featuredImage(): ?MediaAsset
    {
        /** @var MediaAsset|null */
        return $this->mediaAssetsInRole(MediaRole::Featured)->first();
    }

    public function hasFeaturedImage(): bool
    {
        return $this->mediaAssetsInRole(MediaRole::Featured)->exists();
    }

    /**
     * The image used for social sharing: an explicit override if set, otherwise
     * the featured image. Falling back avoids the common failure where a shared
     * link renders with no preview because nobody filled in a second field.
     */
    public function socialShareImage(): ?MediaAsset
    {
        /** @var MediaAsset|null $override */
        $override = $this->mediaAssetsInRole(MediaRole::OgImage)->first();

        return $override ?? $this->featuredImage();
    }

    /**
     * Attach an asset in a role.
     *
     * For singular roles (featured, og_image) any existing attachment in that
     * role is detached first. This is where "exactly one featured image" is
     * actually guaranteed: a partial unique index — unique on
     * (attachable, role) only when role is singular — is not expressible
     * portably in MySQL, so the write path enforces it instead. Validation in
     * the Form Requests covers the "at least one" half of the rule.
     */
    public function attachMediaAsset(MediaAsset $asset, MediaRole $role, ?int $position = null): void
    {
        if ($role->isSingular()) {
            $this->mediaAssetsInRole($role)->detach();
        }

        $this->mediaAssets()->attach($asset->getKey(), [
            'role' => $role->value,
            'position' => $position ?? $this->nextPositionInRole($role),
        ]);

        $this->unsetRelation('mediaAssets');
    }

    public function setFeaturedImage(MediaAsset $asset): void
    {
        $this->attachMediaAsset($asset, MediaRole::Featured);
    }

    public function detachMediaAsset(MediaAsset $asset, ?MediaRole $role = null): void
    {
        $relation = $role !== null
            ? $this->mediaAssetsInRole($role)
            : $this->mediaAssets();

        $relation->detach($asset->getKey());

        $this->unsetRelation('mediaAssets');
    }

    protected function nextPositionInRole(MediaRole $role): int
    {
        return (int) $this->mediaAssetsInRole($role)->max('media_attachments.position') + 1;
    }
}
