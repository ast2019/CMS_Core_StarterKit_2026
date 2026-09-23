<?php

declare(strict_types=1);

namespace App\Services\Api;

use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

/**
 * Tag-based response caching for the Delivery API.
 *
 * Requirement 8.4.
 *
 * Tags are what make targeted invalidation possible: publishing one article should
 * drop the article listings and that article, not the entire cache. Without tags
 * the only options are flushing everything (which stampedes the database on a busy
 * site) or waiting out a TTL (which means an editor publishes and sees nothing
 * change, then publishes again).
 *
 * The complication is that not every store supports tags — Laravel's `file` and
 * `database` stores do not, and `database` is this project's local default so the
 * test suite runs without Redis. So this class degrades to untagged caching rather
 * than throwing, and invalidation falls back to a full flush of its own prefix.
 */
class DeliveryCache
{
    public const TAG_CONTENT = 'cms:content';

    public const TAG_TAXONOMY = 'cms:taxonomy';

    public const TAG_MEDIA = 'cms:media';

    public const TAG_NAVIGATION = 'cms:navigation';

    public const TAG_SETTINGS = 'cms:settings';

    public const TAG_SITEMAP = 'cms:sitemap';

    /**
     * Every tag this service manages, for a blanket invalidation.
     *
     * @var list<string>
     */
    public const ALL_TAGS = [
        self::TAG_CONTENT,
        self::TAG_TAXONOMY,
        self::TAG_MEDIA,
        self::TAG_NAVIGATION,
        self::TAG_SETTINGS,
        self::TAG_SITEMAP,
    ];

    /**
     * Remember a Delivery response payload.
     *
     * The return is `mixed` rather than a threaded generic: Cache::remember() has
     * its own TCacheValue template which PHPStan cannot unify with one declared
     * here, and forcing it produces an unresolvable-template error at every call
     * site. Callers know their own payload type; this only decides whether the
     * callback runs.
     *
     * @param  list<string>  $tags
     * @param  \Closure(): mixed  $callback
     */
    public function remember(string $key, array $tags, \Closure $callback): mixed
    {
        $ttl = (int) config('cms.api.delivery.cache_ttl', 300);

        // A non-positive TTL disables caching outright, which is the honest way to
        // let a site opt out rather than setting a one-second TTL and pretending.
        if ($ttl <= 0) {
            return $callback();
        }

        $key = $this->prefix($key);

        if ($this->supportsTags()) {
            return Cache::tags($tags)->remember($key, $ttl, $callback);
        }

        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Invalidate by tag, or flush this service's keys when tags are unavailable.
     *
     * @param  list<string>  $tags
     */
    public function invalidate(array $tags): void
    {
        if ($this->supportsTags()) {
            Cache::tags($tags)->flush();

            return;
        }

        /*
         * Without tag support the store cannot enumerate keys by tag, and iterating
         * every possible key is not feasible. Flushing the whole store is heavy but
         * correct; serving stale content after a publish is not. Production should
         * use Redis (blueprint §13), where this branch never runs.
         */
        Cache::flush();
    }

    public function invalidateAll(): void
    {
        $this->invalidate(self::ALL_TAGS);
    }

    /**
     * Build a cache key that includes everything the response varies on.
     *
     * Locale is always part of the key. Leaving it out is the classic
     * multilingual caching bug: the first request warms the cache in Persian and
     * every locale afterwards is served Persian until the TTL expires.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function key(string $resource, string $locale, array $parameters = []): string
    {
        ksort($parameters);

        $suffix = $parameters === []
            ? ''
            : ':'.hash('xxh128', (string) json_encode($parameters));

        return "{$resource}:{$locale}{$suffix}";
    }

    public function supportsTags(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }

    private function prefix(string $key): string
    {
        return 'cms:delivery:'.$key;
    }
}
