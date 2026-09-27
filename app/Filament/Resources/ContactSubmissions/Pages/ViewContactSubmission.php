<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactSubmissions\Pages;

use App\Filament\Resources\ContactSubmissions\ContactSubmissionResource;
use App\Models\ContactSubmission;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Submissions are read-only: ContactSubmissionPolicy forbids create and update,
 * because an editable copy of what a visitor sent is no longer a record of what
 * they sent.
 *
 * Requirement 3.1.
 */
class ViewContactSubmission extends ViewRecord
{
    protected static string $resource = ContactSubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markRead')
                ->label(__('cms.action.mark_read'))
                ->icon('heroicon-o-envelope-open')
                ->visible(fn (ContactSubmission $record): bool => ! $record->isRead())
                ->authorize(fn (ContactSubmission $record): bool => auth()->user()?->can('markRead', $record) ?? false)
                ->action(fn (ContactSubmission $record) => $record->markRead()),

            /*
             * Item 16 — spam triage belongs HERE as much as on the list.
             *
             * This is the screen where the decision is actually made: the recovery path for a
             * mis-flagged enquiry is "filter to spam, open it, read it, decide" — and without
             * these actions that path ended on a page with no indication the record was
             * flagged and no way to clear it, forcing the editor back to the list to act on a
             * row they had just finished reading.
             */
            Action::make('markNotSpam')
                ->label(__('cms.action.mark_not_spam'))
                ->icon('heroicon-o-inbox-arrow-down')
                ->visible(fn (ContactSubmission $record): bool => $record->is_spam)
                ->authorize(fn (ContactSubmission $record): bool => auth()->user()?->can('triageSpam', $record) ?? false)
                ->action(fn (ContactSubmission $record) => $record->clearSpamFlag()),

            Action::make('markSpam')
                ->label(__('cms.action.mark_spam'))
                ->icon('heroicon-o-shield-exclamation')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (ContactSubmission $record): bool => ! $record->is_spam)
                ->authorize(fn (ContactSubmission $record): bool => auth()->user()?->can('triageSpam', $record) ?? false)
                ->action(fn (ContactSubmission $record) => $record->flagAsSpam('manual')),

            // Admin-only, per the policy: submissions may hold personal data subject
            // to a retention decision that should not sit with every editor.
            DeleteAction::make(),
        ];
    }

    /**
     * Opening a message marks it read, which is what a reader expects from an
     * inbox and avoids a second click on every single item.
     *
     * Except a FLAGGED one. The unread count already excludes spam
     * (ContactSubmission::scopeNotSpam), so marking it achieves nothing an editor can see —
     * while a triage pass through the spam list would quietly rewrite `read_at` on every row
     * it touched. A write with no visible effect, performed on a page view, is worth not
     * doing.
     */
    protected function afterFill(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof ContactSubmission || $record->is_spam) {
            return;
        }

        if (auth()->user()?->can('markRead', $record)) {
            $record->markRead();
        }
    }
}
