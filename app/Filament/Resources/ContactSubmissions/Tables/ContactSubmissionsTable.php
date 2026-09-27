<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactSubmissions\Tables;

use App\Models\ContactSubmission;
use App\Support\Dates\LocalizedDate;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ContactSubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('read_at')
                    ->label('')
                    ->boolean()
                    ->trueIcon('heroicon-o-envelope-open')
                    ->falseIcon('heroicon-s-envelope')
                    ->getStateUsing(fn (ContactSubmission $record): bool => $record->isRead()),

                TextColumn::make('name')
                    ->label(__('cms.field.name'))
                    ->searchable(),

                TextColumn::make('subject')
                    ->label(__('cms.field.subject'))
                    ->searchable()
                    ->limit(50),

                TextColumn::make('email')
                    ->label(__('cms.field.email'))
                    ->searchable()
                    ->extraAttributes(['class' => 'cms-ltr'])
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('cms.field.publish_date'))
                    ->formatStateUsing(fn (?CarbonInterface $state): ?string => LocalizedDate::format($state))
                    ->sortable(),
            ])
            ->filters([
                Filter::make('unread')
                    ->label(__('cms.filter.unread'))
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<ContactSubmission> $query */
                        return $query->unread();
                    }),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('markRead')
                    ->label(__('cms.action.mark_read'))
                    ->icon('heroicon-o-envelope-open')
                    ->visible(fn (ContactSubmission $record): bool => ! $record->isRead())
                    // Authorised explicitly: the policy makes submissions
                    // non-editable, and marking read is the one permitted mutation.
                    ->authorize(fn (ContactSubmission $record): bool => auth()->user()?->can('markRead', $record) ?? false)
                    ->action(fn (ContactSubmission $record) => $record->markRead()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    /*
                     * Item 32 — reading the inbox is the whole workflow, so clearing
                     * twenty messages should be one action rather than twenty.
                     * markRead() is the only mutation the policy permits on a
                     * submission, and it is authorised per record here as it is on the
                     * row action.
                     */
                    BulkAction::make('markRead')
                        ->label(__('cms.action.mark_read_selected'))
                        ->icon('heroicon-o-envelope-open')
                        ->action(function (Collection $records): void {
                            $marked = 0;

                            foreach ($records as $record) {
                                /** @var ContactSubmission $record */
                                if ($record->isRead()) {
                                    continue;
                                }

                                if (! (auth()->user()?->can('markRead', $record) ?? false)) {
                                    continue;
                                }

                                $record->markRead();
                                $marked++;
                            }

                            Notification::make()
                                ->title(__('cms.action.mark_read_selected_done', ['count' => $marked]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
