<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * News / articles — the primary content type.
 *
 * Requirements 3.1, 3.6, 7.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table): void {
            $table->id();

            // Translatable (Requirement 5.1).
            $table->json('title');
            $table->json('slug');
            $table->json('excerpt')->nullable();

            /*
             * RULE #6 — the editor stores structured TipTap JSON, not HTML.
             * Per locale, so `body` is a JSON object of locale => TipTap
             * document. Requirement 4.4.
             */
            $table->json('body')->nullable();

            /*
             * GEO (blueprint §6): a self-contained first-paragraph answer, per
             * locale. Kept as its own column rather than parsed out of the body
             * at request time, because an answer engine quoting the wrong
             * paragraph is worse than not being quoted.
             */
            $table->json('answer_paragraph')->nullable();

            $table->json('meta_title')->nullable();
            $table->json('meta_description')->nullable();
            $table->json('robots_meta')->nullable();

            // Non-translatable.
            $table->string('status')->default(ContentStatus::Draft->value);

            /*
             * `publish_date` is the editorial go-live moment and drives
             * scheduling: a record with status=published and a future
             * publish_date is NOT yet live (Requirement 3.6). Distinct from
             * created_at, which is when the row appeared.
             */
            $table->timestamp('publish_date')->nullable();

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Decision D-2: the blueprint said `category_id` in §3 and
             * many-to-many in §9. Both are implemented — this FK names the
             * primary category, which drives the canonical URL and the
             * BreadcrumbList JSON-LD (both of which need a single path), while
             * category_content carries the full set.
             */
            $table->foreignId('primary_category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The Delivery API's hot path: published records in date order.
            $table->index(['status', 'publish_date']);
            $table->index('primary_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
