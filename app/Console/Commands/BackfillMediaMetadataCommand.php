<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaAsset;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fill `mime_type`, `size`, `width` and `height` on assets uploaded before the
 * panel started recording them.
 *
 * Requirement 7.6, and three live consequences of the gap:
 *
 *  - the Delivery API serves a null width/height, so a frontend cannot reserve
 *    space and the image lands as a layout shift;
 *  - SchemaBuilder::imageObject() omits width/height when they are null, which
 *    costs the page rich-result layouts it otherwise qualifies for;
 *  - SocialTagBuilder::cardType() falls back to `summary` instead of
 *    `summary_large_image` when dimensions are unknown, so every share of an older
 *    article renders as a thumbnail rather than a hero.
 *
 * MediaAsset::syncFileMetadata() closed the gap for new uploads and for anything
 * re-saved through the MediaAsset pages, but it runs on WRITE — an asset nobody
 * touches again keeps its nulls for ever, and the three symptoms above are silent.
 * Hence a command: this is a one-off correction of historical rows, run once per
 * deployment that predates the fix (see docs/deployment.md).
 *
 * ---------------------------------------------------------------------------
 * Why this does not write an audit row per asset (RULE #8)
 * ---------------------------------------------------------------------------
 * MediaAsset uses IsAuditable, whose options include logEmptyChanges() — so a
 * normal save() here would append one `MediaAsset.updated` row per asset, with no
 * causer, describing a change no editor made. On a real library that is thousands
 * of rows of noise in the one table an investigator reads to answer "who changed
 * this?", which makes the trail worse rather than more complete.
 *
 * RULE #8's subject is "every admin write action", and a CLI backfill is an
 * operator action on machine-derived metadata, not an editorial change. The
 * established precedent for exactly this distinction is
 * App\Listeners\ExtractVideoMetadata, which saveQuietly()s ffprobe's output for the
 * same stated reason.
 *
 * But "no opt-out" also means a bulk write must not be invisible, so the run itself
 * is logged: ONE activity row, in the same `cms` log, carrying the counts and the
 * ids it touched. One row that says what happened is auditable; N rows that each
 * blame nobody are not. Nothing is logged when nothing was updated, because a
 * no-op run is not a fact about the content.
 *
 * Timestamps are left alone, which is the one place this deliberately differs from
 * the upload-time listener. That listener runs at the moment a file genuinely
 * arrives, so bumping `updated_at` is honest. Here the files have not changed —
 * only our record of them — and bumping the whole library at once would reset every
 * asset's "recently touched" ordering in the panel simultaneously, destroying the
 * signal for the sake of a correction nobody made.
 */
class BackfillMediaMetadataCommand extends Command
{
    protected $signature = 'cms:backfill-media-metadata
        {--chunk=200 : Rows per batch}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Fill missing mime type, size and dimensions on media assets uploaded before the panel recorded them.';

    /**
     * Assets whose ids are reported back to the operator, per problem category.
     *
     * Capped when printed: an operator needs to know WHICH assets need attention,
     * and a library with ten thousand orphaned rows should not flood the terminal
     * with all of them.
     */
    private const MAX_REPORTED_IDS = 20;

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $this->newLine();
        $this->line('<options=bold>Media metadata backfill</> — Requirement 7.6');

        if ($dryRun) {
            $this->warn('Dry run: nothing will be written.');
        }

        $scanned = 0;
        $updated = 0;
        $unchanged = 0;
        $updatedIds = [];
        $missingFromDisk = [];
        $noFileAttached = [];

        /*
         * chunkById(), not chunk() or get(). A media library is the largest table in
         * this application by row size once alt_text/caption translations are counted,
         * and loading it whole is what a backfill over tens of thousands of assets
         * cannot afford. chunkById() is also the only safe form here: plain chunk()
         * paginates with OFFSET against a result set this loop is MUTATING, so every
         * row it fixes drops out of the candidate set and shifts the next page,
         * silently skipping assets. Keyset pagination on the primary key cannot skip.
         *
         * The eager load matters as much as the chunking: fileMetadata() and
         * fileIsMissingFromDisk() both read the `media` relation, so without it each
         * asset costs two queries and the memory win is paid back in round trips.
         */
        MediaAsset::query()
            ->missingFileMetadata()
            ->with('media')
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $assets) use (
                $dryRun,
                &$scanned,
                &$updated,
                &$unchanged,
                &$updatedIds,
                &$missingFromDisk,
                &$noFileAttached,
            ): void {
                /** @var Collection<int, MediaAsset> $assets */
                foreach ($assets as $asset) {
                    $scanned++;

                    /*
                     * A missing file must not abort the run. These are exactly the
                     * legacy rows the command exists for, and one of them having lost
                     * its file — a restore that missed the storage volume, a manual
                     * deletion — is the most likely single thing wrong with an old
                     * library. Aborting would mean the operator fixes one row, re-runs,
                     * and hits the next one.
                     */
                    if ($asset->getFirstMedia('file') === null) {
                        $noFileAttached[] = (int) $asset->getKey();

                        continue;
                    }

                    if ($asset->fileIsMissingFromDisk()) {
                        $missingFromDisk[] = (int) $asset->getKey();
                    }

                    // Still attempt the write: mime type and byte size live on the
                    // media row and are real facts even when the file has gone, so a
                    // damaged asset can be partly repaired rather than skipped whole.
                    $metadata = $asset->fileMetadata();

                    $asset->forceFill($metadata);

                    if (! $asset->isDirty()) {
                        // Idempotency made visible: a second run reaches here for
                        // every asset the first run could not complete, writes
                        // nothing, and reports the same unresolved rows.
                        $unchanged++;

                        continue;
                    }

                    $updated++;
                    $updatedIds[] = (int) $asset->getKey();

                    if ($dryRun) {
                        continue;
                    }

                    /*
                     * Quietly and without timestamps — see the class docblock. Quiet
                     * skips the per-asset audit row AND the media-library events, which
                     * would otherwise re-queue conversions for every asset in the
                     * library on a run that changed no file.
                     */
                    MediaAsset::withoutTimestamps(static fn (): bool => $asset->saveQuietly());
                }
            });

        $this->newLine();

        $this->table(['Outcome', 'Assets'], [
            ['Scanned (incomplete metadata)', (string) $scanned],
            [$dryRun ? 'Would be updated' : 'Updated', (string) $updated],
            ['Already complete / unresolvable', (string) $unchanged],
            ['File missing from disk', (string) count($missingFromDisk)],
            ['No file ever attached', (string) count($noFileAttached)],
        ]);

        $this->reportIds('Files missing from disk (dimensions cannot be derived)', $missingFromDisk);
        $this->reportIds('Assets with no file attached at all', $noFileAttached);

        if ($scanned === 0) {
            $this->newLine();
            $this->info('Every media asset already carries its file metadata. Nothing to do.');

            return self::SUCCESS;
        }

        if ($updated > 0 && ! $dryRun) {
            $this->recordAudit($updated, $updatedIds, count($missingFromDisk), count($noFileAttached));
        }

        $this->newLine();

        if ($dryRun) {
            $this->warn('Dry run: nothing was written. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        $this->info("Backfilled {$updated} asset(s).");

        if ($missingFromDisk !== [] || $noFileAttached !== []) {
            /*
             * Reported as a warning and NOT as a failing exit code. The run did
             * everything it could; the leftovers are pre-existing data damage, and
             * failing the command would break the deploy pipeline of every site that
             * has one orphaned asset from three years ago.
             */
            $this->warn('Some assets could not be completed. They are listed above and are safe to re-run over.');
        }

        return self::SUCCESS;
    }

    /**
     * One audit row for the whole run (RULE #8, see the class docblock).
     *
     * `withProperties` rather than the description alone, so the trail can answer
     * "was asset 412 touched by the backfill or by a person?" without re-deriving it.
     *
     * @param  list<int>  $updatedIds
     */
    private function recordAudit(int $updated, array $updatedIds, int $missingFromDisk, int $noFileAttached): void
    {
        activity('cms')
            ->withProperties([
                'updated' => $updated,
                'updated_ids' => $updatedIds,
                'file_missing_from_disk' => $missingFromDisk,
                'no_file_attached' => $noFileAttached,
            ])
            ->log('MediaAsset.metadata_backfilled');
    }

    /**
     * @param  list<int>  $ids
     */
    private function reportIds(string $label, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $shown = array_slice($ids, 0, self::MAX_REPORTED_IDS);
        $suffix = count($ids) > count($shown)
            ? sprintf(' … and %d more', count($ids) - count($shown))
            : '';

        $this->newLine();
        $this->line("<comment>{$label}</comment>");
        $this->line('  ids: '.implode(', ', array_map('strval', $shown)).$suffix);
    }
}
