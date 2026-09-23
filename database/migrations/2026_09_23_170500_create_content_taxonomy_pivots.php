<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decision D-2 — content <-> category is many-to-many, alongside the
 * `primary_category_id` FK on `contents`.
 *
 * Requirement 3.1, and Requirement 4.8 (related content is derived from shared
 * categories and tags, so both pivots are read on every article request).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_content', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            /*
             * Mirrors contents.primary_category_id so a query that already has
             * the pivot loaded can tell which category is canonical without a
             * second lookup. The application keeps the two in step; the FK is
             * the source of truth.
             */
            $table->boolean('is_primary')->default(false);

            $table->unique(['content_id', 'category_id']);
            $table->index(['category_id', 'is_primary']);
        });

        Schema::create('content_tag', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->unique(['content_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_tag');
        Schema::dropIfExists('category_content');
    }
};
