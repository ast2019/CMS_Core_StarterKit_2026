<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decision D-3, tier two: links library assets to content with a role.
 *
 * Polymorphic rather than the `content_media_asset` the design named, because
 * RULE #7 puts featured images on four models (Content, Page, Gallery, Slide)
 * and four near-identical pivot tables would mean four copies of the same
 * attach/detach logic.
 *
 * Requirements 3.2, 3.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_attachments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->morphs('attachable');

            // App\Enums\MediaRole: featured | inline | gallery | og_image.
            $table->string('role');

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            /*
             * One row per (asset, owner, role): the same asset may legitimately
             * be both the featured image and a gallery item of one article, but
             * not attached twice in the same role.
             *
             * Note what this does NOT enforce: "at most one featured image per
             * article". That needs uniqueness on (attachable, role) only when
             * role is singular, and a partial/filtered unique index is not
             * portable across MySQL and SQLite. HasFeaturedImage::attachMediaAsset()
             * detaches the previous singular attachment instead, and the Form
             * Requests cover the "at least one" half.
             */
            $table->unique(
                ['media_asset_id', 'attachable_type', 'attachable_id', 'role'],
                'media_attachments_unique',
            );

            $table->index(['attachable_type', 'attachable_id', 'role'], 'media_attachments_owner_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_attachments');
    }
};
