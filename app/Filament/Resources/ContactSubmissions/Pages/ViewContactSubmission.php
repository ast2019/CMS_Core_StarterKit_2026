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

            // Admin-only, per the policy: submissions may hold personal data subject
            // to a retention decision that should not sit with every editor.
            DeleteAction::make(),
        ];
    }

    /**
     * Opening a message marks it read, which is what a reader expects from an
     * inbox and avoids a second click on every single item.
     */
    protected function afterFill(): void
    {
        $record = $this->getRecord();

        if ($record instanceof ContactSubmission && auth()->user()?->can('markRead', $record)) {
            $record->markRead();
        }
    }
}
