<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SystemHeartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Item 18 — proof that a queue worker is running, obtained the only way it can be.
 *
 * There is no way to ask "is a worker running?" from inside a web request. The jobs table
 * being non-empty means nothing (a worker could be mid-job), and being empty means less
 * (nothing may have been dispatched). The only reliable answer is to put something through
 * the queue and see whether it comes out, which is what this job is.
 *
 * Dispatched once a minute by `cms:heartbeat`. On the `sync` driver it runs inline, which
 * correctly reports "alive": with no separate worker, queued work genuinely does happen.
 *
 * `$tries = 1`, and deliberately NO `retryUntil()`.
 *
 * The first version of this class set both, which is contradictory rather than
 * belt-and-braces: Laravel's worker consults `retryUntil` FIRST and returns early while the
 * deadline is in the future, so the attempt limit was never read
 * (Illuminate\Queue\Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts). A probe that threw
 * was released with no backoff and spun for the whole staleness window, with a fresh one
 * arriving every minute beside it — and past the deadline the worker FAILED it, so draining
 * an hour's backlog wrote sixty rows into the `failed_jobs` table an operator reads to find
 * real failures. A monitoring feature degrading the diagnostic next to it.
 *
 * So the staleness decision is made in handle() instead, where it is plain PHP that can be
 * read and tested, and the retry policy is the single honest one: try once, and if that
 * fails let the stamp go stale. A stale stamp is the true reading — the queue did not
 * deliver — so there is nothing a retry could add except a later timestamp that would
 * describe the outage as health.
 */
class RecordQueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(private readonly int $dispatchedAt) {}

    public function handle(): void
    {
        $lag = max(0, now()->getTimestamp() - $this->dispatchedAt);

        /*
         * A probe that has been queued longer than the staleness window is DISCARDED rather
         * than recorded.
         *
         * This is the one thing that has to be got right, because the alternative is a
         * confident lie: a worker coming back after an hour would process sixty backed-up
         * probes and each would stamp now(), so the dashboard would report a healthy queue
         * at the exact moment the backlog was at its worst. Returning without writing leaves
         * the stamp stale, which is what was actually true for that minute.
         *
         * Not an exception. Nothing went wrong — this probe simply has nothing useful left
         * to say, and throwing would put it in `failed_jobs` as though it had.
         */
        if ($lag > SystemHeartbeat::staleAfterSeconds()) {
            return;
        }

        /*
         * The LAG is the useful number, not just the timestamp. A worker that is running but
         * forty seconds behind is a capacity problem an operator can act on, and it is
         * invisible if all that is recorded is "seen recently".
         *
         * Measured from the dispatch time carried in the payload rather than from the job's
         * own clock, because the two ends are the whole point of the measurement.
         */
        SystemHeartbeat::record(SystemHeartbeat::QUEUE, [
            'lag_seconds' => $lag,
            'connection' => (string) config('queue.default'),
        ]);
    }
}
