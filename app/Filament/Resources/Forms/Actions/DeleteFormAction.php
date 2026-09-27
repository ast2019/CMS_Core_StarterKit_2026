<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms\Actions;

use App\Models\Form;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

/**
 * Item 15 — deleting a form, on its row and on its edit page.
 *
 * Offered only where Form::isDeletable() says so (never the contact form, never a form with
 * submissions). Hidden rather than left to the policy, because Gate::before lets an Admin past
 * every policy method.
 *
 * Re-checked at the moment of deleting, in the manner of GuardedDeleteActions: a submission can
 * arrive between the list rendering and the click, and the model guard underneath
 * (Form::guardDeletion) would then refuse with a validation error keyed on a field this page
 * does not have. Refusing here, with a sentence, is what the editor can actually read.
 */
final class DeleteFormAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (Form $record): bool => $record->isDeletable())
            ->before(function (DeleteAction $action, Form $record): void {
                if ($record->canBeDeletedNow()) {
                    return;
                }

                Notification::make()
                    ->title(__('cms.forms.delete_blocked'))
                    ->warning()
                    ->persistent()
                    ->send();

                $action->halt();
            });
    }
}
