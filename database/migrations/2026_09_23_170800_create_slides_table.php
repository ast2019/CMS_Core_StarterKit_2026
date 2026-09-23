<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Homepage slideshow.
 *
 * Requirements 3.4, 3.5 (hard cap of 5 active slides) and 7.6 (performance
 * contract: preloaded first image, fixed dimensions, no autoplay video).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slides', function (Blueprint $table): void {
            $table->id();

            $table->json('title');
            $table->json('subtitle')->nullable();
            $table->json('cta_label')->nullable();

            $table->string('link')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            /*
             * Requirement 7.6 — no layout shift. Dimensions are stored on the
             * slide so the Delivery API can emit explicit width/height with the
             * payload; without them the browser cannot reserve space and the
             * hero image shifts the page as it loads, which is exactly the CLS
             * the blueprint forbids.
             *
             * Denormalised from the media asset deliberately: the frontend needs
             * them in the same response as the slide, and a slide's crop may
             * differ from the asset's intrinsic size.
             */
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slides');
    }
};
