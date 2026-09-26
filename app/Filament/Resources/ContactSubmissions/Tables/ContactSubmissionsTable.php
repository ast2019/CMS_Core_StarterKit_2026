<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactSubmissions\Tables;

use App\Models\ContactSubmission;
use App\Support\Dates\LocalizedDate;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
