<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Filters\TrashedFilter;

/**
 * Item 10 — the trash, as something an editor can actually reach.
 *
 * THE BUG THIS EXISTS TO FIX. Content, Page and Gallery had soft-deleted since the first
 * migration, and EditContent / EditPage / EditGallery each carried a RestoreAction and a
 * ForceDeleteAction. But no table anywhere had a TrashedFilter, so a deleted record vanished
 * from the only list that links to its edit page — and the restore buttons sat on a screen
 * with no route to it. Two of the three resources at least answered a hand-typed URL
 * (getRecordRouteBindingEloquentQuery); ContentResource did not, so restoring an article was
 * impossible from the panel by any means. The feature was in the codebase and not in the
 * product.
 *
 * Centralised because it is now needed by eight resources, and because the three parts have to
 * agree: a filter with no restore action is a read-only graveyard, and restore actions behind
 * no filter are what this project already had.
 *
 * Every piece is authorised by the model's policy — RestoreAction checks `restore`,
 * ForceDeleteAction checks `forceDelete` (admin-only throughout, per
 * AuthorizesCmsAbilities) — so what a given role sees is decided by the policy rather than
 * here.
 */
class TrashControls
{
    /**
     * The filter that makes deleted records reachable at all.
     *
     * Filament's TrashedFilter defaults to EXCLUDING trashed records, which is what every
     * existing table already did implicitly. So adding it changes nothing about the default
     * view and adds two states: deleted-only, and everything.
     *
     * Labelled explicitly rather than left to Filament's own translations, because those ship
     * in English and this panel is trilingual — a Persian table with an English "Deleted"
     * option is the kind of seam that makes a panel feel half-translated.
     */
    public static function filter(): TrashedFilter
    {
        return TrashedFilter::make()
            ->label(__('cms.trash.filter'))
            ->placeholder(__('cms.trash.without_trashed'))
            ->trueLabel(__('cms.trash.with_trashed'))
            ->falseLabel(__('cms.trash.only_trashed'));
    }

    /**
     * Row actions for a trashed record. Both hide themselves when the record is not trashed.
     *
     * @return list<RestoreAction|ForceDeleteAction>
     */
    public static function recordActions(): array
    {
        return [
            RestoreAction::make(),
            // The GUARDED force delete: the stock action consults nothing, so an administrator could
            // destroy by hand exactly what cms:prune-trash refuses to destroy on a timer.
            GuardedDeleteActions::forceDeleteRecord(),
        ];
    }

    /**
     * Bulk equivalents, for emptying or recovering a trash view in one action.
     *
     * Spread into an existing BulkActionGroup by the caller rather than returning a group of
     * their own, so a table ends up with one grouped menu instead of two.
     *
     * @return list<RestoreBulkAction|ForceDeleteBulkAction>
     */
    public static function bulkActions(): array
    {
        return [
            RestoreBulkAction::make(),
            GuardedDeleteActions::forceDeleteBulk(),
        ];
    }
}
