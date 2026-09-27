<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\ContactSubmission;
use App\Models\SystemHeartbeat;
use App\Services\Api\DeliveryCache;
use App\Support\Dates\LocalizedDate;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Item 18 — whether the machinery behind the panel is actually running.
 *
 * Every failure this reports is SILENT. Nothing in the panel errors when the scheduler
 * stops; scheduled articles simply never appear while the dashboard keeps promising a
 * publish time. Nothing errors when the queue stops either; translations, search indexing
 * and webhooks accumulate in a table nobody looks at. And nothing errors when the cache
 * store cannot do tags — it just means every publish flushes the entire cache.
 *
 * Three cards, each answering a question with a FIX attached rather than a number:
 *
 *  - Scheduler. Stale means cron is not running `schedule:run` for this application, so
 *    scheduled publishing is not happening. docs/deployment.md has the crontab.
 *  - Queue worker. Stale means no worker is consuming the queue. Stale while the SCHEDULER
 *    is also stale is reported as unknown instead, because nothing has been dispatched to
 *    test it and blaming the worker for cron's failure sends an operator to the wrong place.
 *  - Cache store. A store without tag support turns every targeted invalidation into
 *    `Cache::flush()` (see DeliveryCache) — correct, but it discards the whole store,
 *    rate-limiter counters included, on every editorial save.
 *
 * Admin-only. Every remedy here is a server-side change, and a dashboard full of red
 * infrastructure warnings an editor cannot act on is noise that teaches them to ignore the
 * cards that do concern them. The one consequence an editor DOES need — "your scheduled
 * publish will not happen" — is surfaced on ContentOverviewWidget's scheduled card instead,
 * where the promise is made.
 */
class SystemStatusWidget extends StatsOverviewWidget
{
    /**
     * Below the editorial widgets and above the version card. Operational state is
     * something an administrator checks, not the first thing anyone needs to read.
     */
    protected static ?int $sort = 40;

    /**
     * Polling off, as on the other widgets. These stamps change once a minute and nobody
     * watches a dashboard waiting for them; the next page load is soon enough.
     */
    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        /**
         * One query for the whole table, keyed by subsystem. The table holds a handful of
         * rows by construction (record() upserts on `key`), and asking per card would mean
         * four queries to render three, on the panel's landing page.
         *
         * @var Collection<string, SystemHeartbeat> $heartbeats
         */
        $heartbeats = SystemHeartbeat::query()->get()->keyBy('key');

        $stats = [
            $this->schedulerStat($heartbeats->get(SystemHeartbeat::SCHEDULER)),
            $this->queueStat(
                $heartbeats->get(SystemHeartbeat::QUEUE),
                $heartbeats->get(SystemHeartbeat::SCHEDULER),
            ),
            $this->cacheStat($heartbeats->get(SystemHeartbeat::SCHEDULER)),
        ];

        // Only where there is a contact form to protect. Requirement 1.1 — a disabled module
        // contributes nothing, and a card reporting on an endpoint that answers 404 would be
        // a permanent unexplained warning.
        if (config('cms.modules.contact', true)) {
            $stats[] = $this->contactProtectionStat($heartbeats->get(SystemHeartbeat::CONTACT_HONEYPOT));
        }

        return $stats;
    }

    protected function schedulerStat(?SystemHeartbeat $scheduler): Stat
    {
        $alive = $this->isAlive($scheduler);

        return Stat::make(
            __('cms.system.status.scheduler'),
            $alive ? __('cms.system.status.running') : __('cms.system.status.stopped'),
        )
            ->description($alive
                ? $this->lastSeenDescription($scheduler)
                // Names the remedy, because "stopped" on its own sends an administrator
                // looking for a switch in the panel that does not and should not exist.
                : __('cms.system.status.scheduler_stopped_help'))
            ->icon(Heroicon::OutlinedClock)
            ->color($alive ? 'success' : 'danger');
    }

    protected function queueStat(?SystemHeartbeat $queue, ?SystemHeartbeat $scheduler): Stat
    {
        $alive = $this->isAlive($queue);

        /*
         * UNKNOWN, not stopped, when the scheduler is the thing that is down. The queue
         * stamp is written by a job the scheduler dispatches, so with cron stopped there is
         * nothing to measure — and reporting "queue stopped" would send an operator to
         * restart a worker that was never the problem.
         */
        if (! $alive && ! $this->isAlive($scheduler)) {
            return Stat::make(__('cms.system.status.queue'), __('cms.system.status.unknown'))
                ->description(__('cms.system.status.queue_unknown_help'))
                ->icon(Heroicon::OutlinedQueueList)
                ->color('gray');
        }

        return Stat::make(
            __('cms.system.status.queue'),
            $alive ? __('cms.system.status.running') : __('cms.system.status.stopped'),
        )
            ->description($alive
                ? $this->queueLagDescription($queue)
                : __('cms.system.status.queue_stopped_help'))
            ->icon(Heroicon::OutlinedQueueList)
            ->color($alive ? 'success' : 'danger');
    }

    /**
     * The cache store, and whether the web process and the cron process agree about it.
     *
     * The heartbeat stamps the store the CLI resolved; this reads the one the WEB process
     * resolved. Comparing them is the whole reason the command records it — a containerised
     * deployment whose web and cron containers were handed different environments is a real
     * failure mode, and it is otherwise completely invisible: every cache written by one
     * would be unreachable to the other, so an editor's publish would invalidate a store the
     * API never reads from and the site would serve stale pages for ever with nothing
     * anywhere reporting a problem.
     *
     * Reported ahead of the tag warning, because a disagreement makes the tag question moot.
     */
    protected function cacheStat(?SystemHeartbeat $scheduler): Stat
    {
        $webStore = (string) config('cache.default');
        $cliStore = $scheduler?->meta['cache_store'] ?? null;

        if (is_string($cliStore) && $cliStore !== '' && $cliStore !== $webStore) {
            return Stat::make(__('cms.system.status.cache_store'), $webStore)
                ->description(__('cms.system.status.cache_store_mismatch', ['store' => $cliStore]))
                ->icon(Heroicon::OutlinedCircleStack)
                ->color('danger');
        }

        $supportsTags = app(DeliveryCache::class)->supportsTags();

        return Stat::make(__('cms.system.status.cache_store'), $webStore)
            ->description($supportsTags
                ? __('cms.system.status.cache_tags_ok')
                : __('cms.system.status.cache_tags_missing'))
            ->icon(Heroicon::OutlinedCircleStack)
            // Warning rather than danger: a tagless store is a correctness-preserving
            // degradation, not a fault. The site serves the right content; it just throws
            // away more cache than it needed to.
            ->color($supportsTags ? 'success' : 'warning');
    }

    /**
     * Whether the contact form's honeypot is still reaching us (item 16).
     *
     * The check can only fire when the field arrives, and the field is rendered by a frontend
     * in a different repository. So renaming `CMS_CONTACT_HONEYPOT_FIELD` without deploying
     * the frontend — or a frontend rewrite that drops the decoy — switches the site's main
     * spam defence off with no symptom whatsoever: submissions keep arriving, the inbox looks
     * clean, and nothing reports a problem.
     *
     * The comparison is against the NEWEST submission rather than against the clock. A form
     * that receives one enquiry a month is not broken for the other twenty-nine days, and a
     * time-based staleness window would cry wolf on every quiet site. Messages arriving
     * without the decoy is the only thing that means anything, and it means it immediately.
     */
    protected function contactProtectionStat(?SystemHeartbeat $honeypotSeen): Stat
    {
        $newestSubmission = ContactSubmission::query()->max('created_at');

        if ($newestSubmission === null) {
            // Nothing has been submitted, so nothing can be concluded. Reported as such
            // rather than as healthy: a fresh site has not proved its honeypot works.
            return Stat::make(__('cms.system.status.contact_protection'), __('cms.system.status.unknown'))
                ->description(__('cms.system.status.contact_no_submissions'))
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray');
        }

        $lastSeen = $honeypotSeen?->last_seen_at;

        if ($lastSeen !== null && $lastSeen->greaterThanOrEqualTo(Carbon::parse($newestSubmission))) {
            return Stat::make(__('cms.system.status.contact_protection'), __('cms.system.status.running'))
                ->description($this->lastSeenDescription($honeypotSeen))
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('success');
        }

        return Stat::make(__('cms.system.status.contact_protection'), __('cms.system.status.stopped'))
            ->description(__('cms.system.status.contact_honeypot_missing'))
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('warning');
    }

    /**
     * Whether a loaded heartbeat row is recent enough to count as running.
     *
     * A MISSING row counts as not alive, which is the right answer on a fresh install where
     * cron was never wired up — precisely the deployment mistake this widget exists to
     * catch. Treating absent data as "probably fine" would hide it.
     */
    protected function isAlive(?SystemHeartbeat $heartbeat): bool
    {
        return $heartbeat !== null
            && $heartbeat->last_seen_at->greaterThanOrEqualTo(
                now()->subSeconds(SystemHeartbeat::staleAfterSeconds()),
            );
    }

    protected function lastSeenDescription(?SystemHeartbeat $heartbeat): ?string
    {
        $human = LocalizedDate::human($heartbeat?->last_seen_at);

        return $human === null ? null : __('cms.system.status.last_seen', ['ago' => $human]);
    }

    /**
     * How far behind the worker was when it last reported.
     *
     * The lag is the number worth showing: a worker that is running but a minute behind is a
     * capacity problem, and it looks identical to a healthy one if all that is reported is
     * "seen just now". Falls back to the plain last-seen line when the stamp carries no lag
     * — an older row, or one written before this field existed.
     */
    protected function queueLagDescription(?SystemHeartbeat $heartbeat): ?string
    {
        $lag = $heartbeat?->meta['lag_seconds'] ?? null;

        if (! is_int($lag)) {
            return $this->lastSeenDescription($heartbeat);
        }

        return __('cms.system.status.queue_lag', ['seconds' => LocalizedDate::number($lag)]);
    }
}
