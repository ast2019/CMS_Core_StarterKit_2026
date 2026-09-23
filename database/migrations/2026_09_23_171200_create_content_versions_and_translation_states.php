<?php

declare(strict_types=1);

use App\Enums\TranslationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content versions (Requirement 3.7) and the per-locale translation lifecycle
 * (Requirements 5.3, 5.4, 5.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Editorial snapshots, deliberately separate from `activity_log`.
         *
         * They overlap in content but not in purpose: activity_log is an
         * append-only forensic record of who did what and is never pruned
         * (RULE #8), while versions exist to be restored and are pruned to the
         * last N (cms.versions.keep). Collapsing them would force a choice
         * between an audit trail that can be trimmed — which is not an audit
         * trail — and unbounded growth of full row snapshots.
         */
        Schema::create('content_versions', function (Blueprint $table): void {
            $table->id();
            $table->morphs('versionable');

            // Full attribute snapshot, so a restore does not depend on replaying
            // a chain of diffs.
            $table->json('payload');

            $table->unsignedInteger('version_number');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['versionable_type', 'versionable_id', 'version_number'],
                'content_versions_unique_number',
            );
        });

        Schema::create('translation_states', function (Blueprint $table): void {
            $table->id();
            $table->morphs('translatable');

            $table->string('locale', 12);
            $table->string('status')->default(TranslationStatus::NotTranslated->value);

            /*
             * Hash of the source locale's translatable fields at the moment this
             * locale was reviewed. Comparing it to the current hash is what turns
             * `reviewed` into `outdated` (Requirement 5.4).
             *
             * Hash rather than timestamp: a timestamp comparison would mark every
             * translation stale on any save, including one that only touched
             * publish_date, and editors would learn to ignore the flag.
             */
            $table->string('source_hash', 64)->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['translatable_type', 'translatable_id', 'locale'],
                'translation_states_unique_locale',
            );

            // Drives the sitemap eligibility filter (Decision D-5).
            $table->index(['locale', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_states');
        Schema::dropIfExists('content_versions');
    }
};
