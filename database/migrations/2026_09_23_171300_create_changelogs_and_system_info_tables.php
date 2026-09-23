<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RULES #1 and #2 — changelog and semantic versioning as working features.
 *
 * Requirements 10.1, 10.2, 10.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('changelogs', function (Blueprint $table): void {
            $table->id();

            $table->string('version', 32)->unique();

            /*
             * Keep-a-Changelog categories, stored as
             * { "added": [...], "fixed": [...], ... } so the admin panel and
             * CHANGELOG.md render from one source (RULE #1).
             */
            $table->json('entries');

            $table->timestamp('released_at');
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('released_at');
        });

        Schema::create('system_info', function (Blueprint $table): void {
            $table->id();

            // Semantic Versioning, shown in the panel footer (RULE #2).
            $table->string('version', 32);

            $table->timestamp('installed_at')->nullable();
            $table->timestamp('last_migrated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_info');
        Schema::dropIfExists('changelogs');
    }
};
