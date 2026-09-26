<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\Page;
use App\Services\Api\DeliveryCache;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Let a scheduled record actually appear when its time comes.
 *
 * THE BUG THIS EXISTS TO FIX.
 *
 * Scheduling already worked at the database level: a record with status `published`
 * and a future `publish_date` is excluded by HasPublishStatus::live(), and included
 * the moment the clock passes it. No status transition is needed and this command
 * performs none — `live()` is a query, not a flag.
 *
 * What did not work was the CACHE. Delivery responses are cached and invalidated by
 * App\Observers\DeliveryCacheObserver, which fires on model WRITES. At the instant a
 * scheduled article becomes eligible, nothing is written: no save, no event, no
 * observer. So the article stayed invisible until the cached payload expired on its own
 * TTL — up to cms.api.delivery.cache_ttl seconds after the time an editor chose.
 * "Publish at 8am" was not a promise the system kept.
 *
 * WHY THIS IS STATELESS.
 *
 * It asks "did anything become live in the last $lookback seconds?" rather than
 * tracking a watermark of its own. A watermark would have to be persisted, and the
 * obvious place — the Setting singleton — is audited (RULE #8) and observed for cache
 * invalidation, so writing one every minute would spam the audit trail with
 * bookkeeping and bust the settings cache on every tick: this command would become
 * the cache-invalidation problem it was written to solve.
 *
 * The cost of being stateless is that a publish near a boundary can be seen by two
 * consecutive runs, so the tags are invalidated twice. Invalidation is idempotent, so
 * that is wasted work and nothing worse — and the lookback is kept just above the
 * schedule interval to keep the overlap at a single extra run.
 *
 * The lookback MUST exceed the interval this is scheduled at, or a publish landing in
 * the gap is never noticed. The default (90s against a one-minute schedule) has that
 * margin; see cms.scheduling.publish_lookback and bootstrap/app.php.
 *
 * Being stateless also means there is no catch-up: an embargo that elapses while the
 * scheduler is NOT running — a deploy, a paused cron, a killed worker — is outside the
 * window by the time it resumes and is never noticed, so the record waits out the
 * Delivery TTL. Run `cms:publish-due --lookback=86400` once after a deployment to sweep
 * anything that went live while nothing was ticking.
 */
class PublishDueContentCommand extends Command
{
    protected $signature = 'cms:publish-due
        {--lookback= : Seconds to look back; must exceed the schedule interval}';

    protected $description = 'Refresh Delivery caches for content whose scheduled publish time has just passed';

    /**
     * The publishable, cacheable models. Slide is absent deliberately: it has no
     * publish_date, and MenuItem and the taxonomies are not scheduled either.
     *
     * @var list<class-string<Model>>
     */
    private const SCHEDULABLE = [
        Content::class,
        Page::class,
        Gallery::class,
    ];

    public function handle(DeliveryCache $cache): int
    {
        $lookback = $this->lookbackSeconds();

        $now = now();
        $since = $now->copy()->subSeconds($lookback);

        $due = 0;

        foreach (self::SCHEDULABLE as $model) {
            $due += $model::query()
                ->where('status', ContentStatus::Published)
                /*
                 * The window is half-open on the `since` side so a record sitting
                 * exactly on a boundary is counted by one run rather than by both.
                 * Timestamps are stored UTC and now() is UTC (config('app.timezone')
                 * stays UTC by design — only the panel's DISPLAY timezone is Tehran),
                 * so no conversion belongs here.
                 */
                ->where('publish_date', '>', $since)
                ->where('publish_date', '<=', $now)
                ->count();
        }

        if ($due === 0) {
            return self::SUCCESS;
        }

        /*
         * TAG_CONTENT is the one that does the work: a newly live record changes both
         * the list payloads and its own, and those are what a frontend reads.
         *
         * TAG_SITEMAP goes with it for consistency with DeliveryCacheObserver, which
         * pairs the two tags on every content write — but be clear that it purges
         * nothing today. No remember() call in the application stores a sitemap:
         * SitemapController regenerates the XML on every request. What a crawler
         * actually holds is the HTTP cache from that response's
         * `Cache-Control: public, max-age=3600`, which no server-side tag can reach, so
         * up to an hour is the real floor on sitemap freshness after a scheduled
         * publish. Shorten that max-age if an hour is too long for a given site; do not
         * expect this command to change it.
         */
        $cache->invalidate([
            DeliveryCache::TAG_CONTENT,
            DeliveryCache::TAG_SITEMAP,
        ]);

        $this->components->info(sprintf(
            '%d scheduled record(s) became live in the last %ds; Delivery content and sitemap caches refreshed.',
            $due,
            $lookback,
        ));

        return self::SUCCESS;
    }

    /**
     * Seconds to look back, from the option, else config, floored at one second.
     */
    private function lookbackSeconds(): int
    {
        $option = $this->option('lookback');

        if (is_string($option) && trim($option) !== '' && ctype_digit(trim($option))) {
            return max(1, (int) trim($option));
        }

        return max(1, (int) config('cms.scheduling.publish_lookback', 90));
    }
}
