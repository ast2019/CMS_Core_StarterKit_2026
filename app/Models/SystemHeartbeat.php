<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Item 18 — "is the scheduler running? is a queue worker running?"
 *
 * Both were unanswerable from inside the panel, and both fail SILENTLY. A dead scheduler
 * does not error: scheduled articles simply never appear, and the dashboard goes on
 * promising "next publish: 8am" for a publish that will not happen. A dead queue worker
 * does not error either: translations, search indexing and webhooks queue up and nothing
 * anywhere says so. The first person to notice is a reader, or nobody.
 *
 * Deliberately NOT IsAuditable. A row written every minute by a cron job is not an
 * administrative action, and auditing it would bury the writes RULE #8 exists to record
 * under 1,440 heartbeats a day.
 *
 * @property string $key
 * @property Carbon $last_seen_at
 * @property array<string, mixed>|null $meta
 */
class SystemHeartbeat extends Model
{
    /**
     * The scheduler itself — written by `cms:heartbeat`, which the schedule runs every
     * minute. If this is stale, cron is not running this application's scheduler at all,
     * and every scheduled task is silently not happening.
     */
    public const SCHEDULER = 'scheduler';

    /**
     * A queue worker — written by the job `cms:heartbeat` dispatches on each tick.
     *
     * The pairing is what makes the diagnosis precise: the scheduler stamp advancing while
     * this one does not means cron is fine and the worker is dead, which is a different
     * fix. Neither advancing means cron is the problem, and the queue reading is then
     * unknown rather than bad.
     */
    public const QUEUE = 'queue';

    /**
     * The last time a contact submission arrived carrying the honeypot field — i.e. the last
     * time the frontend demonstrably still rendered the decoy.
     *
     * Not a subsystem of this application, which is the point. The field is rendered by a
     * frontend in a different repository, so renaming it here without deploying there turns
     * the site's main spam defence off with no symptom at all: the submissions keep arriving,
     * the inbox looks clean, and nothing reports a problem. This stamp is what makes that
     * visible — compared against the newest submission, it answers "are messages still
     * coming in with the decoy attached?".
     *
     * These two keys are deliberately NOT part of the liveness pair above: `isAlive()`'s
     * few-minutes window is meaningless for a form that may receive one enquiry a week, so
     * they are read through lastSeen() and compared against submission timestamps instead.
     */
    public const CONTACT_HONEYPOT = 'contact_honeypot';

    public const CONTACT_TIMING = 'contact_timing';

    protected $fillable = ['key', 'last_seen_at', 'meta'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * Record that a subsystem is alive, now.
     *
     * An upsert on `key`, so the table holds one row per subsystem forever rather than
     * growing by 1,440 rows a day. That matters: a heartbeat LOG would need pruning, and a
     * monitoring feature that needs its own retention policy is a second thing to get
     * wrong. Nothing here asks "when was it alive an hour ago", so nothing is kept.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function record(string $key, ?array $meta = null): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['last_seen_at' => now(), 'meta' => $meta],
        );
    }

    /**
     * When a subsystem last reported, or null if it never has.
     */
    public static function lastSeen(string $key): ?Carbon
    {
        return static::query()->where('key', $key)->first()?->last_seen_at;
    }

    /**
     * Whether a subsystem has reported recently enough to be considered running.
     *
     * Null — never reported — counts as NOT alive, which is the right answer for a fresh
     * install where cron was never wired up: that is precisely the deployment mistake this
     * exists to catch, and treating "no data" as "probably fine" would hide it.
     */
    public static function isAlive(string $key): bool
    {
        $lastSeen = static::lastSeen($key);

        return $lastSeen !== null && $lastSeen->greaterThanOrEqualTo(now()->subSeconds(self::staleAfterSeconds()));
    }

    /**
     * Seconds of silence after which a subsystem is reported as stopped.
     *
     * Several missed ticks rather than one. The schedule runs every minute, but a single
     * tick can legitimately be missed — a deploy, a slow host, `withoutOverlapping`
     * skipping a run — and a monitor that goes red for that is a monitor people learn to
     * ignore. The floor keeps a mis-set value from making every reading "stopped".
     */
    public static function staleAfterSeconds(): int
    {
        return max(60, (int) config('cms.system.heartbeat.stale_after', 300));
    }
}
