<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SyncSearchIndexes;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps the per-locale search indexes in step with content changes.
 *
 * Requirement 6.4 — index updates are asynchronous, via a queued job.
 *
 * This REPLACES Scout's own model observer, which is disabled for searchable models
 * in CmsServiceProvider. Scout's observer writes one index (whatever
 * `searchableAs()` returns at that moment), which for locale-dependent index names
 * means it would silently index only the request's current locale and leave the
 * other two stale.
 */
class SearchIndexObserver
{
    public function saved(Model $model): void
    {
        $this->dispatch($model);
    }

    /**
     * Soft delete: the record stops being live, so it must leave every index.
     */
    public function deleted(Model $model): void
    {
        $this->dispatch($model, remove: true);
    }

    public function forceDeleted(Model $model): void
    {
        $this->dispatch($model, remove: true);
    }

    /**
     * Restoring makes it live again, so it goes back in — subject to the usual
     * eligibility rules.
     */
    public function restored(Model $model): void
    {
        $this->dispatch($model);
    }

    private function dispatch(Model $model, bool $remove = false): void
    {
        /*
         * afterCommit so the job never reads a row that a rolled-back transaction
         * removed. Without it, a failed publish inside a transaction could still leave
         * the article in the search index — visible in results and 404 on click.
         */
        SyncSearchIndexes::dispatch($model::class, $model->getKey(), $remove)
            ->afterCommit();
    }
}
