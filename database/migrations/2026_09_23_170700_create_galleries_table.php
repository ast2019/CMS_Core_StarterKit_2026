<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requirement 3.1.
 *
 * Decision D-4: a gallery's cover is the single `featured` media attachment and
 * is what RULE #7 governs. Its items are `gallery`-role attachments and are
 * uncapped. The cover may also appear among the items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('galleries', function (Blueprint $table): void {
            $table->id();

            $table->json('title');
            $table->json('slug');
            $table->json('description')->nullable();
            $table->json('meta_title')->nullable();
            $table->json('meta_description')->nullable();
            $table->json('robots_meta')->nullable();

            $table->string('status')->default(ContentStatus::Draft->value);
            $table->timestamp('publish_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'publish_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('galleries');
    }
};
