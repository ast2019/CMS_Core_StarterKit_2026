<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requirement 3.1. Translatable fields are JSON keyed by locale
 * (spatie/laravel-translatable), per Requirement 5.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();

            // Translatable (Requirement 5.1).
            $table->json('name');
            $table->json('slug');
            $table->json('description')->nullable();
            $table->json('meta_title')->nullable();
            $table->json('meta_description')->nullable();
            $table->json('robots_meta')->nullable();

            // Non-translatable.
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('categories')
                // A deleted parent nulls its children rather than cascading:
                // deleting a top-level category must not silently destroy every
                // article grouping beneath it.
                ->nullOnDelete();

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['parent_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
