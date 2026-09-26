<?php

declare(strict_types=1);

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\artisan;

/**
 * `cms:backfill-media-metadata` — Requirement 7.6 for assets that predate the fix.
 *
 * MediaAsset::syncFileMetadata() only runs on write, so an asset uploaded before it
 * existed and never re-saved keeps its null dimensions for ever. The visible cost is
 * three layers down: the Delivery API cannot tell a frontend what space to reserve,
 * SchemaBuilder::imageObject() drops width/height, and SocialTagBuilder::cardType()
 * downgrades the Twitter card to `summary`. These tests are about the command being
 * safe to point at a real library — re-runnable, chunked, and unbothered by a file
 * that has gone missing — rather than about getimagesize() working.
 */
it('fills the metadata of an asset uploaded before the panel recorded it', function (): void {
    $asset = MediaAsset::factory()->withFile()->create([
        'mime_type' => null,
        'size' => null,
        'width' => null,
        'height' => null,
    ]);

    artisan('cms:backfill-media-metadata')->assertSuccessful();

    // The factory's PNG is 16x16 (see MediaAssetFactory::samplePng — deliberately not
    // 1x1, so the conversion pipeline has something real to resize).
    expect($asset->fresh())
        ->width->toBe(16)
        ->height->toBe(16)
        ->mime_type->toBe('image/png')
        ->size->toBeGreaterThan(0);
});

it('changes nothing on a second run', function (): void {
    // Idempotency is the whole operational contract: an operator who is unsure
    // whether this already ran must be able to just run it.
    $asset = MediaAsset::factory()->withFile()->create(['width' => null, 'height' => null]);

    artisan('cms:backfill-media-metadata')->assertSuccessful();

    $after = $asset->fresh();

    artisan('cms:backfill-media-metadata')
        ->expectsOutputToContain('Every media asset already carries its file metadata')
        ->assertSuccessful();

    expect($asset->fresh()->only(['mime_type', 'size', 'width', 'height']))
        ->toBe($after->only(['mime_type', 'size', 'width', 'height']));
});

it('keeps going past an asset whose file is missing from disk', function (): void {
    /*
     * The most likely single thing wrong with an old library: a restore that missed
     * the storage volume, or a manual deletion. Aborting the run would mean the
     * operator fixes one row, re-runs, and hits the next one — so the missing file is
     * REPORTED and the rest of the batch still completes.
     */
    $broken = MediaAsset::factory()->withFile()->create([
        'mime_type' => null,
        'size' => null,
        'width' => null,
        'height' => null,
    ]);
    $healthy = MediaAsset::factory()->withFile()->create(['width' => null, 'height' => null]);

    $media = $broken->getFirstMedia('file');
    Storage::disk($media->disk)->delete($media->getPathRelativeToRoot());

    expect($broken->fileIsMissingFromDisk())->toBeTrue();

    artisan('cms:backfill-media-metadata')
        ->expectsOutputToContain('Files missing from disk')
        // Not a failing exit code: the leftovers are pre-existing data damage, and
        // failing would break the deploy pipeline of every site with one orphaned row.
        ->assertSuccessful();

    // The healthy asset in the same batch was still completed.
    expect($healthy->fresh())->width->toBe(16);

    $broken = $broken->fresh();

    /*
     * The damaged asset is partly repaired rather than skipped whole: mime type and
     * byte size live on the media row and are real facts even with the file gone.
     * Dimensions stay NULL rather than becoming zero — null is what SchemaBuilder and
     * SocialTagBuilder already know how to degrade from.
     */
    expect($broken->mime_type)->toBe('image/png')
        ->and($broken->size)->toBeGreaterThan(0)
        ->and($broken->width)->toBeNull()
        ->and($broken->height)->toBeNull();
});

it('tolerates an asset that never had a file attached', function (): void {
    // A different condition from a missing file — an incomplete upload rather than
    // data loss — and the command reports them separately for that reason.
    MediaAsset::factory()->create(['mime_type' => null, 'size' => null, 'width' => null]);

    artisan('cms:backfill-media-metadata')
        ->expectsOutputToContain('No file ever attached')
        ->assertSuccessful();
});

it('writes nothing under --dry-run', function (): void {
    $asset = MediaAsset::factory()->withFile()->create(['width' => null, 'height' => null]);

    artisan('cms:backfill-media-metadata --dry-run')
        ->expectsOutputToContain('nothing was written')
        ->assertSuccessful();

    expect($asset->fresh()->width)->toBeNull();
});

it('leaves a video alone once its own metadata is complete', function (): void {
    /*
     * Dimensions are only expected of an IMAGE. A video whose width is null (no
     * ffprobe on the box — Decision D-6) must not be a permanent candidate that every
     * run picks up, probes pointlessly, and reports as unresolved.
     */
    $video = MediaAsset::factory()->video()->withFile()->create(['width' => null, 'height' => null]);

    expect(MediaAsset::query()->missingFileMetadata()->whereKey($video->getKey())->exists())->toBeFalse();

    artisan('cms:backfill-media-metadata')->assertSuccessful();

    expect($video->fresh())->width->toBeNull();
});

it('records the run as one audit row rather than one per asset', function (): void {
    /*
     * RULE #8 pulls in two directions here. MediaAsset is auditable with
     * logEmptyChanges(), so a normal save() would append one causer-less
     * `MediaAsset.updated` row per asset — thousands of rows of noise in the table an
     * investigator reads to answer "who changed this?". But "no opt-out" also means a
     * bulk write must not be invisible. One row carrying the counts is auditable; N
     * rows that each blame nobody are not.
     */
    $assets = MediaAsset::factory()->count(3)->withFile()->create(['width' => null, 'height' => null]);

    Activity::query()->delete();

    artisan('cms:backfill-media-metadata')->assertSuccessful();

    $rows = Activity::query()->get();

    expect($rows)->toHaveCount(1);

    $activity = $rows->firstOrFail();

    expect($activity->description)->toBe('MediaAsset.metadata_backfilled')
        ->and($activity->log_name)->toBe('cms')
        ->and($activity->properties['updated'] ?? null)->toBe(3)
        ->and($activity->properties['updated_ids'] ?? null)
        ->toBe($assets->map(fn (MediaAsset $a): int => (int) $a->getKey())->all());
});

it('does not log a run that changed nothing', function (): void {
    // A no-op run is not a fact about the content, and logging it would make the
    // trail noisier for a deploy that had nothing to fix.
    Activity::query()->delete();

    artisan('cms:backfill-media-metadata')->assertSuccessful();

    expect(Activity::query()->count())->toBe(0);
});

it('does not bump updated_at across the whole library', function (): void {
    /*
     * The one place this deliberately differs from ExtractVideoMetadata, which runs
     * when a file genuinely arrives and so is right to bump the timestamp. Here the
     * FILES have not changed — only our record of them — and bumping every asset at
     * once would reset the panel's "recently touched" ordering for the entire library
     * in service of a correction nobody made.
     */
    $asset = MediaAsset::factory()->withFile()->create(['width' => null, 'height' => null]);

    $before = $asset->fresh()->updated_at;

    $this->travel(1)->days();

    artisan('cms:backfill-media-metadata')->assertSuccessful();

    expect($asset->fresh()->width)->toBe(16)
        ->and($asset->fresh()->updated_at->equalTo($before))->toBeTrue();
});

it('walks the library in batches instead of loading it whole', function (): void {
    /*
     * Asserted through the RESULT of a chunk size smaller than the candidate set,
     * because that is where the real hazard lives: plain chunk() paginates with
     * OFFSET against a result set this loop is mutating, so each asset it fixes drops
     * out of the candidate set, shifts the next page, and silently skips a row.
     * chunkById() keyset-paginates on the primary key and cannot skip. With --chunk=2
     * over five assets, an OFFSET-based walk would leave some of them null.
     */
    $assets = MediaAsset::factory()->count(5)->withFile()->create(['width' => null, 'height' => null]);

    artisan('cms:backfill-media-metadata --chunk=2')->assertSuccessful();

    foreach ($assets as $asset) {
        expect($asset->fresh()->width)->toBe(16);
    }
});
