<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\MediaRole;
use App\Models\MediaAsset;

/**
 * A content-bearing model that carries a featured image.
 *
 * RULE #7 — Requirements 3.2, 3.3.
 *
 * Paired with the HasFeaturedImage trait. The trait provides the behaviour; this
 * interface lets callers that only have a Model — a Filament page's getRecord(),
 * a Delivery API resource — state that requirement in a type instead of calling
 * methods the type system cannot see. Without it, every such call site either
 * trips static analysis or gets silently suppressed, and a model that loses the
 * trait would only fail at runtime.
 *
 * Named HasFeaturedMedia rather than HasFeaturedImage to avoid colliding with the
 * trait of that name.
 */
interface HasFeaturedMedia
{
    public function featuredImage(): ?MediaAsset;

    public function hasFeaturedImage(): bool;

    public function setFeaturedImage(MediaAsset $asset): void;

    public function socialShareImage(): ?MediaAsset;

    public function attachMediaAsset(MediaAsset $asset, MediaRole $role, ?int $position = null): void;

    public function detachMediaAsset(MediaAsset $asset, ?MediaRole $role = null): void;
}
