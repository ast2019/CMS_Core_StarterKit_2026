<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Concerns\IsSearchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Writes or removes one record across every locale index.
 *
 * Requirement 6.4 — "update the affected indexes asynchronously via a queued job".
 *
 * Queued because a save must not wait on the search engine. A Meilisearch round trip
 * per locale would add latency to every editor save, and an engine outage would make
 * the panel unusable rather than merely making search stale.
 */
class SyncSearchIndexes implements ShouldQueue
{
    use Queueable;

    /**
     * Retry a few times with backoff: a search engine restart is transient, and losing
     * the update would leave the index permanently out of step with the database.
     */
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * @param  class-string  $modelClass
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly int|string $modelKey,
        private readonly bool $remove = false,
    ) {}

    public function handle(): void
    {
        /*
         * The model is re-fetched by key rather than serialised into the job. A
         * serialised model would carry the attribute values from dispatch time, so two
         * rapid edits could apply out of order and leave the index showing the earlier
         * version. Re-reading always indexes current state.
         *
         * withTrashed so a soft-deleted record can still be removed from the index —
         * it is gone from normal queries but its documents are not.
         */
        $query = $this->modelClass::query();

        if (method_exists($this->modelClass, 'bootSoftDeletes')) {
            $query->withTrashed();
        }

        $model = $query
            ->with(['tags', 'categories', 'translationStates'])
            ->find($this->modelKey);

        if ($model === null) {
            // Hard-deleted between dispatch and execution. Nothing to read, so the
            // documents are removed by key instead.
            $this->removeByKey();

            return;
        }

        if (! in_array(IsSearchable::class, class_uses_recursive($model), true)) {
            return;
        }

        if ($this->remove) {
            $model->removeFromSearchIndexes();

            return;
        }

        $model->syncSearchIndexes();
    }

    /**
     * Remove documents for a record that no longer exists.
     */
    private function removeByKey(): void
    {
        $model = new $this->modelClass;

        if (! in_array(IsSearchable::class, class_uses_recursive($model), true)) {
            return;
        }

        $model->setAttribute($model->getKeyName(), $this->modelKey);
        $model->exists = true;

        $model->removeFromSearchIndexes();
    }

    /**
     * A permanently failed sync leaves the index inconsistent with the database, which
     * is worth a log line: nothing else surfaces it, and the symptom (a stale or
     * missing search result) is easy to blame on the engine instead.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('Failed to sync search indexes.', [
            'model' => $this->modelClass,
            'key' => $this->modelKey,
            'remove' => $this->remove,
            'error' => $exception?->getMessage(),
        ]);
    }
}
