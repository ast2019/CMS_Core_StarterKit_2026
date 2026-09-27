<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use App\Services\Content\UsageInspector;
use App\Support\Plural;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Item 11 — delete actions that answer in three ways instead of one.
 *
 * Soft deletes made "are you sure?" the wrong question. A trashed record leaves its foreign
 * keys and pivot rows in place while every relation pointing at it starts resolving to null,
 * so some deletes are recoverable, some quietly change published output, and some carry other
 * records down with them — and the stock confirmation dialog presents all three identically.
 *
 *   BLOCKED   — the delete would break a stated rule (RULE #7's featured image, or the primary
 *               category that decides an article's canonical URL). Refused, naming what to fix
 *               first. Silently degrading a published page is not something an editor should be
 *               able to do by accident from a list row.
 *   CASCADING — the delete implies others (a menu item's children). Allowed, saying how many go
 *               with it.
 *   IN USE    — safe but not free (a tag on forty articles). Allowed, with the count, because
 *               "are you sure?" with no number attached is a question nobody can answer.
 *
 * The BLOCKED rule is also enforced on the models themselves — MediaAsset::guardFeaturedImageUse
 * and Category::guardPrimaryCategoryUse, both consulting UsageInspector::blockedReason() — so a
 * seeder, an import or the Management API cannot get past it either. UsageInspector itself refuses
 * nothing; it only answers. This class exists so that the panel refuses with a sentence before the
 * model is asked, rather than surfacing a validation error keyed on a field no list page has.
 */
class GuardedDeleteActions
{
    /**
     * A delete action for a single record, on a table row or an edit page.
     *
     * The consequence is put in the CONFIRMATION, not discovered afterwards. Filament resolves
     * the description per record, so the count shown is this record's.
     */
    public static function record(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(fn (Model $record): ?string => self::describeConsequence($record))
            ->before(function (DeleteAction $action, Model $record): void {
                $blocked = app(UsageInspector::class)->blockedReason($record);

                if ($blocked === null) {
                    return;
                }

                /*
                 * Reported as a WARNING and halted, rather than allowed to reach the model guard
                 * and surface as a validation error. The action is stopped before the delete so
                 * nothing is half-done, and the message names the record to fix rather than the
                 * rule that was broken.
                 */
                Notification::make()
                    ->title(Plural::trans($blocked['key'], $blocked['parameters']))
                    ->warning()
                    ->persistent()
                    ->send();

                $action->halt();
            });
    }

    /**
     * A bulk delete that skips what it must not delete instead of failing the whole selection.
     *
     * The stock DeleteBulkAction issues one query for every selected record, which with a
     * model-level guard in place means the first blocked record aborts the batch and the editor
     * cannot tell which of forty rows caused it — or how many were already deleted. So the
     * default action is replaced: each record is judged, the allowed ones are deleted, and the
     * report says plainly how many were kept and why.
     */
    public static function bulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->action(fn (DeleteBulkAction $action, EloquentCollection|Collection|LazyCollection $records) => self::applyToSelection(
                $action,
                $records,
                fn (Model $record): ?array => app(UsageInspector::class)->blockedReason($record),
                fn (Model $record) => $record->delete(),
            ))
            ->deselectRecordsAfterCompletion();
    }

    /**
     * A permanent delete for a single record, judged by the stricter rule.
     *
     * The stock ForceDeleteAction consults nothing, so the guard written because "a scheduled task
     * doing that quietly, at night, to records nobody is looking at is the worst possible place for
     * it" protected cms:prune-trash and not the administrator doing the same thing by hand at 11am.
     * An admin could destroy a category a trashed article points at, and `nullOnDelete` silently
     * rewrote that article.
     */
    public static function forceDeleteRecord(): ForceDeleteAction
    {
        return ForceDeleteAction::make()
            ->before(function (ForceDeleteAction $action, Model $record): void {
                $blocked = app(UsageInspector::class)->blockedFromPermanentDeletion($record);

                if ($blocked === null) {
                    return;
                }

                /*
                 * Halted with a notification rather than left to the model guard. The model throws a
                 * ValidationException keyed on a field name, and a list page or an edit page has no
                 * field by that name — so nothing rendered and the button appeared to do nothing at
                 * all.
                 */
                Notification::make()
                    ->title(Plural::trans($blocked['key'], $blocked['parameters']))
                    ->warning()
                    ->persistent()
                    ->send();

                $action->halt();
            });
    }

    /**
     * The bulk equivalent, mirroring bulk() rather than the stock action.
     *
     * The stock ForceDeleteBulkAction destroys per record and reports a failure per throw, so the
     * first record that trips a model guard fails mid-batch — and for a media asset that happens
     * AFTER its files have been removed from disk. Leaving the irreversible path unguarded while
     * the reversible one was careful had it exactly the wrong way round.
     */
    public static function forceDeleteBulk(): ForceDeleteBulkAction
    {
        return ForceDeleteBulkAction::make()
            ->action(fn (ForceDeleteBulkAction $action, EloquentCollection|Collection|LazyCollection $records) => self::applyToSelection(
                $action,
                $records,
                fn (Model $record): ?array => app(UsageInspector::class)->blockedFromPermanentDeletion($record),
                fn (Model $record) => $record->forceDelete(),
            ))
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Apply a delete to a selection, skipping what a guard refuses, and report once.
     *
     * Shared by both bulk actions because the SHAPE of the problem is identical: judge each record,
     * act on the ones that pass, and tell the editor how many were kept back and why. Two copies
     * would drift the first time one of them was fixed.
     *
     * reportBulkProcessingFailure() is the part that is not obvious and was missing. Filament
     * optimistically sets `successfulSelectedRecordsCount = totalSelectedRecordsCount` before the
     * closure runs, and Action::getStatus() returns Success when those are equal — so replacing the
     * action closure without reporting failures made Filament send its OWN "Deleted" success toast
     * alongside the custom warning. An editor saw "1 record kept back" and a green "Deleted" at the
     * same time, which is a panel contradicting itself.
     *
     * @param  EloquentCollection<int, Model>|Collection<int, Model>|LazyCollection<int, Model>  $records
     * @param  \Closure(Model): (array{key: string, parameters: array<string, int|string>}|null)  $guard
     * @param  \Closure(Model): mixed  $delete
     */
    private static function applyToSelection(
        DeleteBulkAction|ForceDeleteBulkAction $action,
        EloquentCollection|Collection|LazyCollection $records,
        \Closure $guard,
        \Closure $delete,
    ): void {
        $blocked = [];

        foreach ($records as $record) {
            $reason = $guard($record);

            if ($reason !== null) {
                // Collected rather than counted, so the notification can say WHICH rule was hit.
                // Two refusals for two different reasons are two different pieces of work.
                $blocked[] = Plural::trans($reason['key'], $reason['parameters']);

                $action->reportBulkProcessingFailure();

                continue;
            }

            $delete($record);
        }

        if ($blocked === []) {
            // Filament's own success notification is correct and sufficient here.
            return;
        }

        /*
         * Deduplicated: forty articles sharing one primary category produce forty identical
         * sentences, and a notification that repeats itself forty times is one nobody reads to the
         * end of. Filament reports the COUNTS from the failures above; this carries the reasons,
         * which it has no way to know.
         */
        Notification::make()
            ->title(__('cms.action.delete_selected_blocked'))
            ->body(implode(' ', array_unique($blocked)))
            ->warning()
            ->persistent()
            ->send();
    }

    /**
     * What this particular delete will do beyond deleting the record, as one sentence, or null
     * when it will do nothing else.
     *
     * Null matters as much as the sentence: a record nothing depends on should get the plain
     * confirmation rather than a reassuring paragraph, so that the paragraph means something
     * when it does appear.
     *
     * Public so a test can assert the sentence itself. Filament renders an action's modal
     * lazily — it is not in the list page's HTML even once the action is mounted — so asserting
     * through the rendered panel would mean asserting nothing at all, quietly.
     */
    public static function describeConsequence(Model $record): ?string
    {
        $inspector = app(UsageInspector::class);

        $notes = [];

        $cascading = $inspector->cascadingDescendantCount($record);

        if ($cascading > 0) {
            // Plural::choice localises the digits as well as the noun form. The raw int once put
            // an ASCII "2" next to a Persian "۲" in the same paragraph.
            $notes[] = Plural::choice('cms.trash.cascade', $cascading);
        }

        $usage = $inspector->usage($record);

        if ($usage !== []) {
            $notes[] = __('cms.usage.in_use', ['usage' => self::describeUsage($usage)]);
        }

        return $notes === [] ? null : implode(' ', $notes);
    }

    /**
     * "۴ مطلب، ۲ زیردسته" — counts in the reader's own digits, labels from the lang files.
     *
     * Public for the replace-file action (item 12), so a warning about the same usage reads the same.
     *
     * @param  array<string, int>  $usage
     */
    public static function describeUsage(array $usage): string
    {
        $parts = [];

        foreach ($usage as $label => $count) {
            $parts[] = Plural::choice($label, $count);
        }

        // The Arabic comma reads correctly in Persian and Arabic and is tolerable in English,
        // where this string is a parenthetical list rather than prose.
        return implode('، ', $parts);
    }
}
