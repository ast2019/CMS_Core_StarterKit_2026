<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\ContentVersion;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Restorable editorial snapshots. Requirement 3.7.
 */
trait HasContentVersions
{
    public static function bootHasContentVersions(): void
    {
        /*
         * Snapshot on `updated`, not `saved`.
         *
         * A snapshot of a brand-new record is just its creation, which the audit
         * log already records; storing it too would mean every article carries a
         * version that is identical to itself and the "restore" list starts with
         * a meaningless entry.
         */
        static::updated(function (self $model): void {
            $model->recordVersion();
        });
    }

    /**
     * @return MorphMany<ContentVersion, $this>
     */
    public function versions(): MorphMany
    {
        return $this->morphMany(ContentVersion::class, 'versionable')
            ->orderByDesc('version_number');
    }

    /**
     * Snapshot the state *before* the update that triggered this.
     *
     * getOriginal() is deliberate: the version list is a history of previous
     * states to roll back to. Storing the new state would make the newest
     * version identical to the live record, so restoring it would be a no-op and
     * the actual previous state would be lost.
     */
    public function recordVersion(?string $reason = null): ?ContentVersion
    {
        $payload = $this->versionablePayload($this->getOriginal());

        // Nothing meaningful changed (for instance only a timestamp moved), so
        // there is nothing worth keeping.
        if ($payload === []) {
            return null;
        }

        /** @var ContentVersion $version */
        $version = $this->versions()->create([
            'payload' => $payload,
            'version_number' => $this->nextVersionNumber(),
            'user_id' => auth()->id(),
            'reason' => $reason,
        ]);

        $this->pruneVersions();

        return $version;
    }

    /**
     * Restore a previous snapshot.
     *
     * The restore is itself an update, so it produces a new version capturing
     * what was replaced — rolling back is undoable rather than destructive.
     */
    public function restoreVersion(ContentVersion $version): void
    {
        $restorable = array_intersect_key(
            $version->payload,
            array_flip($this->versionedAttributes()),
        );

        $this->forceFill($restorable)->save();
    }

    /**
     * Attributes worth snapshotting: content, not bookkeeping.
     *
     * @return list<string>
     */
    public function versionedAttributes(): array
    {
        return array_values(array_diff(
            array_keys($this->getAttributes()),
            ['id', 'created_at', 'updated_at', 'deleted_at'],
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function versionablePayload(array $attributes): array
    {
        return array_intersect_key(
            $attributes,
            array_flip($this->versionedAttributes()),
        );
    }

    protected function nextVersionNumber(): int
    {
        return (int) $this->versions()->max('version_number') + 1;
    }

    /**
     * Keep only the most recent N snapshots (cms.versions.keep).
     *
     * Unbounded growth would be significant: each row is a full copy of every
     * translatable field, including the TipTap body for three locales.
     */
    protected function pruneVersions(): void
    {
        $keep = (int) config('cms.versions.keep', 20);

        if ($keep <= 0) {
            return;
        }

        $obsolete = $this->versions()
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($obsolete->isNotEmpty()) {
            ContentVersion::query()->whereKey($obsolete)->delete();
        }
    }
}
