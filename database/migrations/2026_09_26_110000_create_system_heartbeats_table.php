<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 18 — proof that the scheduler and the queue worker are actually running.
 *
 * WHY A TABLE, AND NOT ANY OF THE THREE OBVIOUS ALTERNATIVES.
 *
 * Not the CACHE, which is where a heartbeat usually goes. This project's default store is
 * `database`, which does not support tags, so DeliveryCache::invalidate() degrades to
 * `Cache::flush()` — meaning every publish erases the whole store. The heartbeat would
 * disappear at the exact moment the site is busiest and the dashboard would report the
 * worker dead because an editor published an article. A monitor whose readings are wiped
 * by normal use is worse than no monitor, because the false alarm trains people to ignore
 * it.
 *
 * Not `system_info`, the existing singleton. That row describes the INSTALL — version,
 * install date, last migration — and is read to render the panel footer. A heartbeat is
 * written every minute forever, so folding it in would churn that row's `updated_at` on a
 * timer and destroy the one question it can currently answer ("has this install changed?").
 *
 * Not the `settings` table. Settings are audited (RULE #8) and observed for Delivery cache
 * invalidation, so a per-minute write would flood the audit trail with bookkeeping and bust
 * the settings cache on every tick — exactly the trap PublishDueContentCommand's docblock
 * documents for a watermark.
 *
 * So: a table of its own, addressed by key, holding one row per monitored subsystem. It is
 * two columns and an upsert per minute, it survives a cache flush, and it belongs to
 * nothing else that could be damaged by writing to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_heartbeats', function (Blueprint $table): void {
            $table->id();

            /*
             * Which subsystem reported in — `scheduler`, `queue`. A string key rather than
             * an enum column so adding a monitored subsystem needs no migration; the set of
             * keys that MATTER is declared in App\Models\SystemHeartbeat.
             */
            $table->string('key', 64)->unique();

            /*
             * When it last reported. Nullable-free: a row only exists because something
             * reported, and "never reported" is represented by the absence of the row
             * rather than by a null — which keeps "we have never seen the worker" and "the
             * worker reported at some unknown time" from collapsing into one state.
             */
            $table->timestamp('last_seen_at');

            /*
             * Freeform detail from the reporter, so a heartbeat can carry the one or two
             * facts that make it actionable — how long the queue took to pick the job up,
             * which host answered. Nullable because a bare heartbeat is still a heartbeat.
             */
            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_heartbeats');
    }
};
