<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's database notifications table, which Filament's notification bell reads.
 *
 * Added for queued work that outlives the request that started it. AI translation
 * runs on the queue (App\Jobs\TranslateRecordJob), so its outcome cannot be flashed
 * to the screen — the request finished minutes earlier. Without a durable channel a
 * translator clicks "translate", is told it was queued, and then never learns
 * whether it worked, which is worse than the synchronous version that at least
 * failed visibly.
 *
 * The shape is Laravel's standard `notifications` table (uuid primary key,
 * polymorphic notifiable, JSON data, nullable read_at); Filament reads it through
 * the same DatabaseNotification model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
