<?php

declare(strict_types=1);

use App\Services\Api\DeliveryCache;
use Illuminate\Cache\RateLimiter as RateLimiterManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| Item 2 — the rate-limiter store must survive a content-driven cache flush
|--------------------------------------------------------------------------
|
| On a tagless store, publishing content runs DeliveryCache::invalidate(), which
| degrades to Cache::flush() on the DEFAULT store. Laravel's RateLimiter resolves its
| backing store from config('cache.limiter') (Illuminate\Cache\CacheServiceProvider).
| These tests pin the reported bug and its fix: when the limiter shares the default
| store the flush wipes its counters, and when it lives in a separate store the
| counters survive.
|
| The default store is set to `file` here (as tests/Feature/Filament/SystemStatusWidgetTest
| does) because that is the tagless path where invalidate() falls back to a full flush;
| the array store used by the rest of the suite is taggable and would never hit that
| branch. A plain `array` store stands in for the separate Redis limiter store so no
| daemon is required — the isolation being tested is the store boundary, not the driver.
|
*/

/**
 * Point config('cache.limiter') at the given store and rebuild the RateLimiter singleton
 * so it binds to that store, mirroring how CacheServiceProvider wires it at boot.
 */
function useLimiterStore(?string $store): void
{
    config()->set('cache.limiter', $store);

    app()->forgetInstance(RateLimiterManager::class);

    app()->singleton(RateLimiterManager::class, function ($app) {
        return new RateLimiterManager($app->make('cache')->driver(
            $app['config']->get('cache.limiter')
        ));
    });

    RateLimiter::clearResolvedInstance(RateLimiterManager::class);
}

beforeEach(function (): void {
    // The tagless default store is the one whose publish invalidation degrades to a full
    // Cache::flush() — the exact condition the segmentation protects against.
    config()->set('cache.default', 'file');
    Cache::store('file')->flush();

    // A separate array store, isolated from the default store, standing in for a Redis
    // limiter store that a flush of the default store cannot reach.
    config()->set('cache.stores.limiter_test', ['driver' => 'array', 'serialize' => false]);

    // The tagless store is the whole point of these cases.
    expect(app(DeliveryCache::class)->supportsTags())->toBeFalse();
});

it('keeps rate-limiter counters when they live in a store the content cache never flushes', function (): void {
    useLimiterStore('limiter_test');

    RateLimiter::hit('cms-delivery:203.0.113.7');

    expect(RateLimiter::attempts('cms-delivery:203.0.113.7'))->toBe(1);

    // A content publish: on the tagless default store this flushes the whole DEFAULT store.
    app(DeliveryCache::class)->invalidate([DeliveryCache::TAG_CONTENT]);

    // The counter lives in the separate limiter store, so the flush did not touch it.
    expect(RateLimiter::attempts('cms-delivery:203.0.113.7'))->toBe(1);
});

it('wipes rate-limiter counters when they share the store the content cache flushes', function (): void {
    // The reported bug: limiter unset means it shares the (tagless) default store.
    useLimiterStore(null);

    RateLimiter::hit('cms-delivery:203.0.113.7');

    expect(RateLimiter::attempts('cms-delivery:203.0.113.7'))->toBe(1);

    app(DeliveryCache::class)->invalidate([DeliveryCache::TAG_CONTENT]);

    // The flush of the shared default store took the counter with it.
    expect(RateLimiter::attempts('cms-delivery:203.0.113.7'))->toBe(0);
});

it('only ever flushes the default store, leaving a named limiter store intact', function (): void {
    // Guards the invariant the fix relies on: DeliveryCache::invalidate() on a tagless
    // store flushes the DEFAULT store and nothing else.
    Cache::store('limiter_test')->put('counter', 42, 600);
    Cache::store('file')->put('delivery-key', 'value', 600);

    app(DeliveryCache::class)->invalidate([DeliveryCache::TAG_CONTENT]);

    expect(Cache::store('limiter_test')->get('counter'))->toBe(42)
        ->and(Cache::store('file')->get('delivery-key'))->toBeNull();
});
