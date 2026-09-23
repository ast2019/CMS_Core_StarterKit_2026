<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\ContentVersion;
use Illuminate\Support\Collection;

/**
 * A model that keeps restorable editorial snapshots.
 *
 * Requirement 3.7.
 *
 * Paired with the HasContentVersions trait. Declared as an interface for the same
 * reason as Publishable and HasFeaturedMedia: a Filament page's getRecord() is
 * typed to Model, so without it every version call site either trips static
 * analysis or gets suppressed — and suppression is what lets a model silently
 * lose the trait while the restore action keeps appearing in its toolbar.
 *
 * Note what is NOT on this contract: the `versions()` relation itself. MorphMany's
 * declaring-model parameter is bounded to Model and is not covariant, so a
 * relation signature here cannot be reconciled with the precise `$this` the trait
 * returns. Exposing the snapshots as a Collection is both expressible and a better
 * contract — callers want the history, not a query builder they might mutate.
 */
interface Versionable
{
    /**
     * Most recent snapshots, newest first.
     *
     * @return Collection<int, ContentVersion>
     */
    public function latestVersions(int $limit = 20): Collection;

    public function recordVersion(?string $reason = null): ?ContentVersion;

    public function restoreVersion(ContentVersion $version): void;

    public function findVersion(int|string $versionId): ?ContentVersion;

    /**
     * @return list<string>
     */
    public function versionedAttributes(): array;
}
