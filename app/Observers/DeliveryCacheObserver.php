<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Category;
use App\Models\ContactSetting;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Setting;
use App\Models\Slide;
use App\Models\Tag;
use App\Services\Api\DeliveryCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Busts the Delivery cache when the underlying content changes.
 *
 * Requirement 8.4 — "invalidate the cache by tag when underlying content changes".
 *
 * Registered for every model whose data can appear in a Delivery response. The
 * mapping is explicit rather than "flush everything on any write", so publishing
 * an article does not also discard the cached navigation and settings that are
 * read on every single request.
 */
class DeliveryCacheObserver
{
    /**
     * Model => tags to invalidate when it changes.
     *
     * @var array<class-string, list<string>>
     */
    public const MODEL_TAGS = [
        Content::class => [DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_SITEMAP],
        Page::class => [DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_SITEMAP],
        Gallery::class => [DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_SITEMAP],
        Slide::class => [DeliveryCache::TAG_CONTENT],

        // Taxonomy changes alter article payloads too (an article carries its
        // category and tag names), so both tags go.
        Category::class => [DeliveryCache::TAG_TAXONOMY, DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_SITEMAP],
        Tag::class => [DeliveryCache::TAG_TAXONOMY, DeliveryCache::TAG_CONTENT],

        // Alt text lives on the asset, so editing it changes every response that
        // embeds that image.
        MediaAsset::class => [DeliveryCache::TAG_MEDIA, DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_SITEMAP],

        MenuItem::class => [DeliveryCache::TAG_NAVIGATION],
        Setting::class => [DeliveryCache::TAG_SETTINGS],
        ContactSetting::class => [DeliveryCache::TAG_SETTINGS],
    ];

    public function __construct(private readonly DeliveryCache $cache) {}

    public function saved(Model $model): void
    {
        $this->bust($model);
    }

    public function deleted(Model $model): void
    {
        $this->bust($model);
    }

    /**
     * Soft-deleted content disappears from Delivery responses, and restoring it
     * brings it back, so both transitions must invalidate.
     */
    public function restored(Model $model): void
    {
        $this->bust($model);
    }

    private function bust(Model $model): void
    {
        $tags = self::MODEL_TAGS[$model::class] ?? null;

        if ($tags === null) {
            return;
        }

        $this->cache->invalidate($tags);
    }
}
