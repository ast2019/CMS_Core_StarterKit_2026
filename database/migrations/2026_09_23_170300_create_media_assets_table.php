<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reusable media library (Decision D-3, tier one).
 *
 * A MediaAsset owns its Spatie Media Library rows, which hold the actual file on
 * the local `public` disk (RULE #9). The asset carries the per-locale alt_text
 * and caption, so describing an image once makes it correct everywhere it is
 * used — the whole point of a library over per-model uploads.
 *
 * Requirements 2.4, 2.6, 2.7, 3.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();

            // Translatable (Requirement 2.7 — alt_text per locale).
            $table->json('alt_text')->nullable();
            $table->json('caption')->nullable();

            $table->string('type')->default('image'); // image | video | document
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            /*
             * Video metadata (Decision D-6).
             *
             * `duration_seconds` and the thumbnail are what a Video Sitemap
             * needs. Google requires a thumbnail; duration is only recommended,
             * so duration stays nullable while publishing a locally hosted video
             * without a thumbnail is blocked in the panel.
             *
             * `external_embed_url` covers the "external embed field" half of
             * blueprint §10 — an embedded video supplies its own thumbnail and
             * needs no ffprobe extraction at all.
             */
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('external_embed_url')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
