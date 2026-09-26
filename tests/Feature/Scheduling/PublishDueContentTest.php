<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\Page;
use App\Services\Api\DeliveryCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\artisan;

/**
 * Scheduled publishing, end to end.
 *
 * The database side always worked: HasPublishStatus::live() excludes a future
 * publish_date and includes a past one, with no status transition involved. What did
 * not work was the CACHE — Delivery responses are invalidated by model writes
 * (DeliveryCacheObserver), and at the moment an embargo elapses nothing is written, so
 * a scheduled article stayed behind a cached payload and out of the cached sitemap.
 * `cms:publish-due` is the tick that notices.
 */
beforeEach(function (): void {
    // A tagged store, so invalidate() can be observed as a targeted purge rather than
    // the full flush DeliveryCache degrades to on an untaggable driver.
    config()->set('cache.default', 'array');
    config()->set('cms.api.delivery.cache_ttl', 300);
});

it('refreshes the Delivery content cache when a scheduled record becomes live', function (): void {
    $cache = app(DeliveryCache::class);

    Content::factory()->create([
        'status' => ContentStatus::Published,
        // Thirty seconds ago: inside the default 90s lookback.
        'publish_date' => now()->subSeconds(30),
    ]);

    /*
     * Cached AFTER the write. Creating the record fires DeliveryCacheObserver, which
     * invalidates these tags itself, so seeding the cache first would let the observer
     * take credit for what this command is supposed to do.
     */
    $cache->remember($cache->key('news.index', 'fa'), [DeliveryCache::TAG_CONTENT], fn (): array => ['stale']);

    artisan('cms:publish-due')->assertSuccessful();

    expect($cache->remember($cache->key('news.index', 'fa'), [DeliveryCache::TAG_CONTENT], fn (): array => ['fresh']))
        ->toBe(['fresh']);

    /*
     * Note what is NOT asserted: a sitemap refresh. Nothing in the application stores a
     * sitemap payload — SitemapController regenerates the XML per request behind
     * `Cache-Control: max-age=3600` — so an assertion about TAG_SITEMAP here could only
     * be written by seeding a cache key no production path ever writes, which would
     * prove the tag mechanism works and say nothing about sitemaps.
     */
});

it('does nothing when no record crossed its publish time', function (): void {
    $cache = app(DeliveryCache::class);

    // Published long ago, and one still embargoed: neither CROSSED the line just now.
    Content::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => now()->subDays(3),
    ]);
    Content::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => now()->addHour(),
    ]);

    // Cached AFTER the writes: creating a record fires DeliveryCacheObserver, which
    // invalidates this tag on its own. Seeding first would prove nothing.
    $cache->remember($cache->key('news.index', 'fa'), [DeliveryCache::TAG_CONTENT], fn (): array => ['cached']);

    artisan('cms:publish-due')->assertSuccessful();

    /*
     * The cache must survive. This runs every minute; invalidating on every tick would
     * make the Delivery cache pointless — and on the default `database` store, where
     * DeliveryCache cannot tag, invalidation degrades to a full Cache::flush().
     */
    expect($cache->remember($cache->key('news.index', 'fa'), [DeliveryCache::TAG_CONTENT], fn (): array => ['fresh']))
        ->toBe(['cached']);
});

it('ignores a draft whose publish date has passed', function (): void {
    $cache = app(DeliveryCache::class);

    // A draft is not scheduled, whatever its date says — live() gates on both.
    Content::factory()->create([
        'status' => ContentStatus::Draft,
        'publish_date' => now()->subSeconds(30),
    ]);

    // Cached after the write, which invalidates the tag by itself.
    $cache->remember($cache->key('news.index', 'fa'), [DeliveryCache::TAG_CONTENT], fn (): array => ['cached']);

    artisan('cms:publish-due')->assertSuccessful();

    expect($cache->remember($cache->key('news.index', 'fa'), [DeliveryCache::TAG_CONTENT], fn (): array => ['fresh']))
        ->toBe(['cached']);
});

it('notices every schedulable model, not just articles', function (string $factory): void {
    $cache = app(DeliveryCache::class);

    $factory::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => now()->subSeconds(10),
    ]);

    // Cached after the write, so only the command can be what refreshes it.
    $cache->remember($cache->key('news.index', 'fa'), [DeliveryCache::TAG_CONTENT], fn (): array => ['stale']);

    artisan('cms:publish-due')->assertSuccessful();

    expect($cache->remember($cache->key('news.index', 'fa'), [DeliveryCache::TAG_CONTENT], fn (): array => ['fresh']))
        ->toBe(['fresh']);
})->with([
    // The three entries of PublishDueContentCommand::SCHEDULABLE. Slide is absent from
    // it deliberately: it has no publish_date.
    'Content' => [Content::class],
    'Page' => [Page::class],
    'Gallery' => [Gallery::class],
]);

it('leaves the cache alone on an untagged store when nothing is due', function (): void {
    /*
     * The production default is `database`, which cannot tag, so DeliveryCache::
     * invalidate() degrades to a full Cache::flush(). That makes the zero-due early
     * return load-bearing rather than an optimisation: without it this command would
     * wipe the entire application cache — settings map, scheduler mutexes and all —
     * once a minute, forever.
     */
    config()->set('cache.default', 'database');

    // Still embargoed, so nothing is due when the command runs.
    Content::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => now()->addHour(),
    ]);

    /*
     * Seeded AFTER the write, which on this store flushes everything by itself — that is
     * the pre-existing behaviour for any editor action. What is under test is that the
     * COMMAND does not do it too, once a minute, forever.
     */
    Cache::put('unrelated-key', 'survives', 600);

    artisan('cms:publish-due')->assertSuccessful();

    expect(Cache::get('unrelated-key'))->toBe('survives');
});

it('honours the lookback window', function (): void {
    $cache = app(DeliveryCache::class);

    // Ten minutes ago: outside the default 90s window, so a normal tick misses it.
    Content::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => now()->subMinutes(10),
    ]);

    // Cached after the write, which invalidates the tag by itself.
    $cache->remember($cache->key('news.index', 'fa'), [DeliveryCache::TAG_CONTENT], fn (): array => ['cached']);

    artisan('cms:publish-due')->assertSuccessful();

    expect($cache->remember($cache->key('news.index', 'fa'), [DeliveryCache::TAG_CONTENT], fn (): array => ['a']))
        ->toBe(['cached']);

    /*
     * …and a wider window catches it. This is the knob that matters: the lookback MUST
     * exceed the schedule interval, or a publish landing between two runs is never
     * noticed and the article waits for the TTL.
     */
    artisan('cms:publish-due --lookback=3600')->assertSuccessful();

    expect($cache->remember($cache->key('news.index', 'fa'), [DeliveryCache::TAG_CONTENT], fn (): array => ['b']))
        ->toBe(['b']);
});

it('makes a scheduled article visible to the Delivery API once its time passes', function (): void {
    /*
     * The behaviour an editor actually cares about, with the cache in the way. The
     * article is fetched while embargoed (so the empty list is cached), then its time
     * passes, and the tick is what makes the next fetch tell the truth.
     */
    $content = Content::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => now()->addMinutes(5),
        'title' => ['fa' => 'مطلب زمان‌بندی‌شده'],
    ]);

    $this->getJson('/api/v1/news?locale=fa')->assertOk()->assertJsonCount(0, 'data');

    // The clock moves past the embargo. No write happens — which is the whole problem.
    $this->travelTo(now()->addMinutes(6));

    artisan('cms:publish-due')->assertSuccessful();

    $this->getJson('/api/v1/news?locale=fa')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $content->getKey());
});

it('registers the scheduled tasks the deployment guide tells operators to run', function (): void {
    /*
     * bootstrap/app.php had no withSchedule() at all, while docs/deployment.md and
     * docs/coolify.md both instruct operators to run `schedule:work`. A worker
     * faithfully running an empty schedule is the failure this pins: the commands have
     * to be registered for the cron to be worth starting.
     */
    /*
     * Through `schedule:list` rather than by resolving the Schedule directly: the
     * withSchedule() callback is applied when the CONSOLE kernel bootstraps, which a
     * feature test does not do, so app(Schedule::class)->events() is empty here and
     * would make this test pass or fail for a reason unrelated to the registration.
     * Running the command exercises the path an operator's cron actually takes.
     */
    Artisan::call('schedule:list');

    $output = Artisan::output();

    expect($output)->toContain('cms:publish-due')
        ->and($output)->toContain('queue:prune-batches')
        ->and($output)->toContain('queue:prune-failed')
        // Every minute, because the panel lets an editor pick a publish time to the
        // minute and an hourly tick would make that precision a lie.
        ->and($output)->toContain('* * * * *');

    /*
     * And the audit log is NOT pruned, deliberately: RULE #8 makes it append-only with
     * no opt-out, so a retention task would be the opt-out it forbids. Asserted so a
     * future "tidy up the tables" change has to argue with a test.
     */
    expect($output)->not->toContain('activitylog');
});
