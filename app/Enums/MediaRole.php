<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The `role` column on the `content_media_asset` pivot.
 *
 * Decision D-3: blueprint §9 asked for a content<->media pivot with a role
 * field, but Spatie Media Library is polymorphic one-to-many — a `media` row
 * belongs to exactly one model and cannot be shared between articles. Since
 * §3 also makes Media Asset a first-class reusable library, the resolution is
 * two-tier: MediaAsset owns the Spatie media, and this enum distinguishes the
 * purpose of each attachment to a piece of content.
 *
 * Requirements 2.4, 3.2.
 */
enum MediaRole: string
{
    /** RULE #7 — exactly one per content-bearing model. */
    case Featured = 'featured';

    /** Referenced from inside the editor's TipTap document. */
    case Inline = 'inline';

    /** A member of a gallery's ordered item list. */
    case Gallery = 'gallery';

    /** Social-share override; falls back to Featured when absent. */
    case OgImage = 'og_image';

    public function label(): string
    {
        return match ($this) {
            self::Featured => __('cms.media_role.featured'),
            self::Inline => __('cms.media_role.inline'),
            self::Gallery => __('cms.media_role.gallery'),
            self::OgImage => __('cms.media_role.og_image'),
        };
    }

    /**
     * Roles restricted to a single asset per model.
     */
    public function isSingular(): bool
    {
        return in_array($this, [self::Featured, self::OgImage], strict: true);
    }
}
