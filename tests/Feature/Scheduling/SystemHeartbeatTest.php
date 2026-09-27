<?php

declare(strict_types=1);

use App\Jobs\RecordQueueHeartbeat;
use App\Models\SystemHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

/*
|--------------------------------------------------------------------------
| Item 18 — the heartbeat itself
|--------------------------------------------------------------------------
|
| Two failures were previously unanswerable from inside the application, and both are
| silent: cron not running the scheduler, and no worker consuming the queue. The tests
| below pin the three properties that make the answer trustworthy — the stamp survives a
| cache flush, a missing stamp reads as "not running" rather than as "probably fine", and
| the scheduler and queue readings are recorded separately so one cannot be blamed for the
| other's failure.
|
*/

it('records the scheduler and dispatches a queue probe on each tick', function (): void {
    Queue::fake();

    artisan('cms:heartbeat')->assertSuccessful();

    expect(SystemHeartbeat::lastSeen(SystemHeartbeat::SCHEDULER))->not->toBeNull();

    // The queue reading cannot be taken from inside a web request: a non-empty jobs table
    // means nothing and an empty one means less. The only reliable answer is to put
    // something through the queue and see whether it comes out.
    Queue::assertPushed(RecordQueueHeartbeat::class);
});

it('records the queue stamp with the lag the job observed', function (): void {
    // The lag, not just the timestamp: a worker that is running but a minute behind is a
    // capacity problem, and it looks identical to a healthy one if all that is stored is
    // "seen recently".
    (new RecordQueueHeartbeat(now()->getTimestamp() - 12))->handle();

    $heartbeat = SystemHeartbeat::query()->where('key', SystemHeartbeat::QUEUE)->sole();

    expect($heartbeat->meta['lag_seconds'])->toBe(12)
        ->and($heartbeat->meta['connection'])->toBe(config('queue.default'));
});

it('stamps the scheduler even when the queue cannot be reached', function (): void {
    /*
     * Order matters. The scheduler stamp is written BEFORE the dispatch, so a broken queue
     * connection still leaves proof that cron ran — otherwise one problem would erase both
     * readings and a queue fault would be misreported as a dead scheduler, sending an
     * operator to restart the wrong thing.
     */
    config()->set('queue.default', 'not-a-configured-connection');

    try {
        Artisan::call('cms:heartbeat');
    } catch (InvalidArgumentException) {
        // The dispatch failing is the premise of this test, not its subject.
    }

    expect(SystemHeartbeat::isAlive(SystemHeartbeat::SCHEDULER))->toBeTrue()
        ->and(SystemHeartbeat::lastSeen(SystemHeartbeat::QUEUE))->toBeNull();
});

it('discards a probe that sat in the queue longer than the staleness window', function (): void {
    /*
     * THE lie this guards against. A worker coming back after an hour processes sixty backed-up
     * probes, and if each stamped now() the dashboard would report a perfectly healthy queue at
     * the exact moment the backlog was at its worst — a monitor describing an outage as health.
     *
     * Discarded silently rather than thrown: nothing went wrong, this probe simply has nothing
     * useful left to say, and an exception would file it in `failed_jobs` as though it had.
     */
    config()->set('cms.system.heartbeat.stale_after', 300);

    (new RecordQueueHeartbeat(now()->getTimestamp() - 3600))->handle();

    expect(SystemHeartbeat::lastSeen(SystemHeartbeat::QUEUE))->toBeNull();
});

it('records a probe that is late but still within the window', function (): void {
    // The boundary in the other direction: a worker forty seconds behind IS running, and the
    // lag is the reading that says so.
    config()->set('cms.system.heartbeat.stale_after', 300);

    (new RecordQueueHeartbeat(now()->getTimestamp() - 40))->handle();

    expect(SystemHeartbeat::query()->where('key', SystemHeartbeat::QUEUE)->sole()->meta['lag_seconds'])
        ->toBe(40);
});

it('gives the queue probe one attempt and no retry deadline', function (): void {
    /*
     * These two settings are not additive in Laravel — they are contradictory. The worker reads
     * `retryUntil` FIRST and returns early while the deadline is in the future, so setting both
     * meant `$tries` was never consulted: a throwing probe was released with no backoff and
     * spun for the whole window, and past the deadline it was FAILED, writing one `failed_jobs`
     * row per minute of downtime into the table an operator reads to find real failures.
     *
     * The staleness decision lives in handle() instead, where it is plain readable PHP. Pinned
     * because the combination looks harmless and reads as thorough.
     */
    $job = new RecordQueueHeartbeat(now()->getTimestamp());

    expect($job->tries)->toBe(1)
        ->and(method_exists($job, 'retryUntil'))->toBeFalse();
});

it('keeps only one row per subsystem however often it reports', function (): void {
    // An upsert, not a log. A heartbeat log would need its own retention policy, and a
    // monitoring feature that needs pruning is a second thing to get wrong.
    SystemHeartbeat::record(SystemHeartbeat::SCHEDULER);
    SystemHeartbeat::record(SystemHeartbeat::SCHEDULER);
    SystemHeartbeat::record(SystemHeartbeat::SCHEDULER);

    expect(SystemHeartbeat::query()->where('key', SystemHeartbeat::SCHEDULER)->count())->toBe(1);
});

it('treats a subsystem that has never reported as not running', function (): void {
    // The state of a fresh install where cron was never wired up — precisely the mistake
    // this exists to catch. Reading "no data" as healthy would hide it.
    expect(SystemHeartbeat::lastSeen(SystemHeartbeat::SCHEDULER))->toBeNull()
        ->and(SystemHeartbeat::isAlive(SystemHeartbeat::SCHEDULER))->toBeFalse();
});

it('tolerates a few missed ticks before reporting a subsystem stopped', function (): void {
    /*
     * A single missed tick is not a fault — a deploy, a slow host, a container restart —
     * and a monitor that goes red for one is a monitor people learn to ignore.
     */
    config()->set('cms.system.heartbeat.stale_after', 300);

    SystemHeartbeat::record(SystemHeartbeat::SCHEDULER);
    SystemHeartbeat::query()->where('key', SystemHeartbeat::SCHEDULER)
        ->update(['last_seen_at' => now()->subSeconds(120)]);

    expect(SystemHeartbeat::isAlive(SystemHeartbeat::SCHEDULER))->toBeTrue();

    SystemHeartbeat::query()->where('key', SystemHeartbeat::SCHEDULER)
        ->update(['last_seen_at' => now()->subSeconds(400)]);

    expect(SystemHeartbeat::isAlive(SystemHeartbeat::SCHEDULER))->toBeFalse();
});

it('refuses a staleness window shorter than the schedule interval', function (): void {
    // A one-second window against a one-minute schedule would report every subsystem
    // stopped, permanently. The floor keeps a mis-set value from making the widget useless.
    config()->set('cms.system.heartbeat.stale_after', 1);

    expect(SystemHeartbeat::staleAfterSeconds())->toBe(60);
});

it('survives the cache flush that a publish performs', function (): void {
    /*
     * THE reason this is a table and not a cache entry.
     *
     * The default store is `database`, which has no tag support, so
     * DeliveryCache::invalidate() degrades to Cache::flush() — every publish wipes the
     * whole store. A cached heartbeat would vanish at the moment the site is busiest and
     * the dashboard would report the worker dead because an editor published an article.
     */
    SystemHeartbeat::record(SystemHeartbeat::SCHEDULER);

    Cache::flush();

    expect(SystemHeartbeat::isAlive(SystemHeartbeat::SCHEDULER))->toBeTrue();
});

it('schedules the heartbeat every minute and without a mutex', function (): void {
    /*
     * Through `schedule:list` first, for the reason PublishDueContentTest documents: the
     * withSchedule() callback is applied when the CONSOLE kernel bootstraps, which a feature
     * test does not do on its own — so resolving Schedule directly would find it empty and
     * the test would fail for a reason unrelated to the registration.
     */
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('cms:heartbeat');

    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'cms:heartbeat'));

    // A heartbeat coarser than the task it vouches for could report healthy through several
    // missed publishes, so the interval has to match cms:publish-due.
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        /*
         * And deliberately WITHOUT a mutex. withoutOverlapping is right for publish-due,
         * whose run can be slow, but here it would be the bug: the mutex is released on
         * SIGTERM and not on a SIGKILL or an OOM kill, so one hard-killed run would suppress
         * the heartbeat and the dashboard would report the scheduler dead while cron was
         * faithfully running it — a false alarm caused by the monitoring itself.
         */
        ->and($event->withoutOverlapping)->toBeFalse();
});
