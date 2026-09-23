<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Static pages, including the brandable 404 (Requirements 3.1, 3.8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();

            $table->json('title');
            $table->json('slug');

            /*
             * The blueprint's §3 called this `blocks[]` while News has `body`,
             * implying two different editors. Unified on one RichEditor storing
             * TipTap JSON: two editors would mean two sets of custom blocks to
             * build and maintain, and editors switching mental models between
             * content types. Column name kept as `blocks` to match the blueprint.
             */
            $table->json('blocks')->nullable();

            $table->json('meta_title')->nullable();
            $table->json('meta_description')->nullable();
            $table->json('robots_meta')->nullable();

            $table->string('status')->default(ContentStatus::Draft->value);
            $table->timestamp('publish_date')->nullable();
            $table->unsignedInteger('position')->default(0);

            /*
             * Requirement 3.8 — the 404 page is a Page record, so it is
             * brandable and editable like any other. `system_key` marks pages the
             * application resolves by name ('404', 'maintenance') rather than by
             * slug. System pages are protected from deletion by their Policy:
             * deleting the 404 page would leave the site with no error page at
             * all, and an editor tidying up would have no reason to expect that.
             */
            $table->string('system_key')->nullable()->unique();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
