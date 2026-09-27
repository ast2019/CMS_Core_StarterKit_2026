<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RecordQueueHeartbeat;
use App\Models\SystemHeartbeat;
use Illuminate\Console\Command;

/**
 * Item 18 — the one scheduled task whose only job is to prove the scheduler ran.
 *
 * Two stamps from one tick, and the PAIR is what makes it diagnostic:
 *
 *   scheduler fresh, queue fresh  — both running.
 *   scheduler fresh, queue stale  — cron is fine, no worker is consuming the queue. Every
 *                                   translation, search index update and webhook is piling
 *                                   up, and nothing else in the panel would say so.
 *   scheduler stale               — cron is not running this application's scheduler, so
 *                                   scheduled publishing is not happening either. The queue
 *                                   reading is then UNKNOWN rather than bad, because
 *                                   nothing has been dispatched to test it.
 *
 * Deliberately not folded into `cms:publish-due`, which already runs every minute. That
 * command is load-bearing for correctness and carries `withoutOverlapping`, so a slow or
 * skipped run is a normal outcome for it — and a heartbeat that goes stale because the task
 * carrying it was legitimately skipped is a false alarm. This one does two writes and
 * cannot be slow.
 */
class HeartbeatCommand extends Command
{
    protected $signature = 'cms:heartbeat';

    protected $description = 'Record that the scheduler and the queue worker are running (item 18)';

    public function handle(): int
    {
        SystemHeartbeat::record(SystemHeartbeat::SCHEDULER, [
            // Which store the Delivery cache is on, captured here rather than read live by
            // the dashboard, so the panel reports the CLI's view of the configuration. A
            // deployment whose web container and cron container disagree about .env is a
            // real failure mode, and it is otherwise invisible.
            'cache_store' => (string) config('cache.default'),
        ]);

        /*
         * Dispatched AFTER the scheduler stamp, so a failure to reach the queue still
         * leaves proof that cron ran. The other order would lose both readings to one
         * problem and misreport a broken queue connection as a dead scheduler.
         */
        RecordQueueHeartbeat::dispatch(now()->getTimestamp());

        return self::SUCCESS;
    }
}
