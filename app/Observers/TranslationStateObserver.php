<?php

declare(strict_types=1);

namespace App\Observers;

use App\Concerns\IsSearchable;
use App\Jobs\NotifyFrontendOfChange;
use App\Jobs\SyncSearchIndexes;
use App\Models\TranslationState;
use App\Services\Api\DeliveryCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Side effects of a TranslationState write that the parent-model observers miss.
 *
 * Reviewing (or demoting) a locale only touches this row. SearchIndexObserver and
 * DeliveryCacheObserver watch Content/Page/Gallery/…, so without this hook a review
 * left the locale out of search and left Delivery SEO payloads cached as noindex
 * until TTL — Decision D-5 opening a door the index never walked through.
 */
class TranslationStateObserver
{
    public function __construct(private readonly DeliveryCache $cache) {}

    public function saved(TranslationState $state): void
    {
        $this->syncParent($state);
    }

    public function deleted(TranslationState $state): void
    {
        $this->syncParent($state);
    }

    private function syncParent(TranslationState $state): void
    {
        $parent = $state->translatable;

        if (! $parent instanceof Model) {
            return;
        }

        /*
         * Content payloads carry translation_status / robots; sitemaps decide membership
         * from the state. Bust both. Taxonomy is included because a Category review
         * changes category payloads too, and TAG_CONTENT alone would leave those warm.
         */
        $this->cache->invalidate([
            DeliveryCache::TAG_CONTENT,
            DeliveryCache::TAG_TAXONOMY,
            DeliveryCache::TAG_SITEMAP,
        ]);

        if (in_array(IsSearchable::class, class_uses_recursive($parent), true)) {
            SyncSearchIndexes::dispatch($parent::class, $parent->getKey())
                ->afterCommit();
        }

        if (NotifyFrontendOfChange::isConfigured()
            && in_array($parent::class, FrontendWebhookObserver::WATCHED, true)) {
            NotifyFrontendOfChange::dispatch($parent::class, $parent->getKey(), 'saved');
        }
    }
}
