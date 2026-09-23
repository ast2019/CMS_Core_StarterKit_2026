<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\ContentVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Spatie\Translatable\HasTranslations;

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
     * Most recent snapshots, newest first, with their authors eager-loaded.
     *
     * The Versionable contract exposes this rather than the relation, so callers
     * holding only a Model-typed record can read the history without the relation
     * generics problem (see the contract for why).
     *
     * @return Collection<int, ContentVersion>
     */
    public function latestVersions(int $limit = 20): Collection
    {
        return $this->versions()->with('author')->limit($limit)->get();
    }

    public function findVersion(int|string $versionId): ?ContentVersion
    {
        /** @var ContentVersion|null */
        return $this->versions()->whereKey($versionId)->first();
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
        $payload = array_intersect_key(
            $attributes,
            array_flip($this->versionedAttributes()),
        );

        return $this->normaliseTranslatableValues($payload);
    }

    /**
     * Guarantee translatable values are stored as locale maps, not JSON strings.
     *
     * getOriginal() can hand back either shape depending on whether the attribute
     * had been read through spatie's accessor before the save. An inconsistent
     * payload means every consumer — the restore path, the history view, a test —
     * has to handle both, and the one that forgets fails on a subset of records
     * for reasons that look random.
     *
     * Normalising once here makes the snapshot shape a guarantee.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normaliseTranslatableValues(array $payload): array
    {
        /*
         * Both models using this trait are translatable today, so a
         * method_exists() probe here is always true and static analysis says so.
         * The capability is still worth stating explicitly for a future
         * non-translatable versioned model, so it is expressed as a trait check.
         */
        $translatable = in_array(
            HasTranslations::class,
            class_uses_recursive(static::class),
            true,
        )
            ? $this->getTranslatableAttributes()
            : [];

        foreach ($translatable as $attribute) {
            if (! array_key_exists($attribute, $payload) || ! is_string($payload[$attribute])) {
                continue;
            }

            $decoded = json_decode($payload[$attribute], true);

            if (is_array($decoded)) {
                $payload[$attribute] = $decoded;
            }
        }

        return $payload;
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
