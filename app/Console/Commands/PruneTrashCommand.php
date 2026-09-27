<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Slide;
use App\Models\Tag;
use App\Services\Content\UsageInspector;
use App\Support\Dates\LocalizedDate;
use App\Support\Plural;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Item 10 — empty the trash on a timer, because otherwise nothing ever leaves it.
 *
 * The question this answers is the one the user asked when the trash was proposed: a deleted
 * record has to actually be deleted eventually, or "delete" means "hide for ever" and the
 * tables — and the disk, for media — grow without bound while an editor believes they have
 * cleaned up.
 *
 * Retention rather than immediacy. `cms.trash.keep_days` (30 by default) is long enough that
 * the mistake this whole feature exists to undo — deleting the wrong thing — is always
 * recoverable in practice, because nobody notices a missing article a month later for the first
 * time.
 *
 * WHAT IT REFUSES TO DO. A force delete is irreversible, so each candidate is checked against
 * UsageInspector::blockedFromPermanentDeletion() first, which is stricter than the interactive
 * check: it counts dependents that are THEMSELVES in the trash. Destroying the category that a
 * trashed article recorded as its primary one would leave that article restorable but wrong —
 * a null canonical segment — and destroying an asset a trashed article uses as its featured
 * image would break RULE #7 on the day it came back. A scheduled task doing that quietly, at
 * night, to records nobody is looking at, is the worst possible place for it to happen. Those
 * records stay in the trash and the command says how many and why.
 *
 * NOT scheduled to run alongside the audit log's non-existent pruning: RULE #8 makes the audit
 * trail append-only, and the rows this command writes when it destroys a record are part of it.
 * The trash is prunable because a trashed record is content; the record OF its deletion is not.
 */
class PruneTrashCommand extends Command
{
    protected $signature = 'cms:prune-trash
        {--days= : Override cms.trash.keep_days for this run}
        {--dry-run : Report what would be destroyed without destroying anything}';

    protected $description = 'Permanently delete records that have been in the trash past the retention window (item 10)';

    /**
     * Every soft-deleting model, in the order they are pruned.
     *
     * DEPENDENTS FIRST, and the order is load-bearing rather than tidy. Content, Page and
     * Gallery are what hold a primary category and a featured-image attachment, so destroying
     * them first releases the references that would otherwise make a category or an asset
     * unprunable — which means a single scheduled run cleans up a coherent set instead of
     * needing thirty more days to get to the taxonomy.
     *
     * @var list<class-string<Model>>
     */
    private const PRUNABLE = [
        Content::class,
        Page::class,
        Gallery::class,
        MenuItem::class,
        Slide::class,
        MediaAsset::class,
        Category::class,
        Tag::class,
    ];

    public function handle(UsageInspector $inspector): int
    {
        $days = $this->keepDays();
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $destroyed = 0;
        $kept = 0;
        $failed = 0;

        foreach (self::PRUNABLE as $model) {
            /*
             * onlyTrashed() with an explicit cursor rather than a mass `forceDelete()` on the
             * query. A bulk delete would be one statement and would skip everything that makes
             * this safe: the per-record usage check, the audit row, and — for a media asset —
             * Media Library's own deleting hook, which is what removes the stored FILE. A mass
             * force delete would leave every conversion of every pruned asset on disk for ever,
             * which is the opposite of the reason this command exists.
             */
            foreach ($model::onlyTrashed()->where('deleted_at', '<=', $cutoff)->cursor() as $record) {
                $blocked = $inspector->blockedFromPermanentDeletion($record);

                if ($blocked !== null) {
                    $kept++;

                    continue;
                }

                if ($dryRun) {
                    $destroyed++;

                    continue;
                }

                /*
                 * CONTAINED PER RECORD, and this is not defensive padding.
                 *
                 * Without it, one throwing forceDelete() — a model guard on a row written before
                 * that guard existed, or a foreign key that is neither cascade nor null — propagated
                 * out of the loop. Everything destroyed before it stayed destroyed, nothing after it
                 * was examined, and report() never ran, so the operator got a stack trace with no
                 * count of what had actually been removed. `withoutOverlapping(30)` then put the next
                 * attempt roughly a day away, so a single bad row stalled retention for 24 hours.
                 *
                 * Counted and reported rather than swallowed: a row that cannot be destroyed is
                 * something an operator needs to know about, and it is a different fact from a row
                 * deliberately kept back.
                 */
                try {
                    $record->forceDelete();
                    $destroyed++;
                } catch (Throwable $exception) {
                    $failed++;

                    $this->components->warn(sprintf(
                        '%s #%s: %s',
                        class_basename($record),
                        (string) $record->getKey(),
                        $exception->getMessage(),
                    ));
                }
            }
        }

        $this->report($destroyed, $kept, $failed, $days, $dryRun);

        return self::SUCCESS;
    }

    private function report(int $destroyed, int $kept, int $failed, int $days, bool $dryRun): void
    {
        if ($destroyed === 0 && $kept === 0 && $failed === 0) {
            $this->info(__('cms.trash.nothing_pruned'));

            return;
        }

        $this->info(($dryRun ? '[dry-run] ' : '').Plural::choice('cms.trash.pruned', $destroyed, ['days' => LocalizedDate::number($days)]));

        if ($kept > 0) {
            // Surfaced as a warning rather than logged quietly: a record that keeps being kept
            // is a dangling reference somebody should look at, and a silent skip would let it
            // sit there for ever.
            $this->warn(Plural::choice('cms.trash.prune_blocked', $kept));
        }

        if ($failed > 0) {
            // Distinct from `kept`: that was a decision, this was a surprise.
            $this->warn(Plural::choice('cms.trash.prune_failed', $failed));
        }
    }

    /**
     * Days a record stays in the trash before it is destroyed.
     *
     * Floored at one day. A value of zero would destroy a record in the same run that an editor
     * deleted it, which is not a trash at all — and it is the sort of thing somebody sets while
     * testing and forgets to put back.
     */
    private function keepDays(): int
    {
        $option = $this->option('days');

        $days = is_numeric($option)
            ? (int) $option
            : (int) config('cms.trash.keep_days', 30);

        return max(1, $days);
    }
}
