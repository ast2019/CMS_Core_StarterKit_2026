<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tags\Tables;

use App\Filament\Tables\GuardedDeleteActions;
use App\Filament\Tables\TrashControls;
use App\Models\Tag;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('cms.field.name'))
                    ->getStateUsing(fn (Tag $record): string => $record->getTranslation('name', app()->getLocale()))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereJsonContainsLocale('name', app()->getLocale(), "%{$search}%", 'like')),

                TextColumn::make('slug')
                    ->label(__('cms.field.slug'))
                    ->getStateUsing(fn (Tag $record): string => (string) $record->getTranslation('slug', app()->getLocale()))
                    ->extraAttributes(['class' => 'cms-ltr'])
                    ->toggleable(),

                TextColumn::make('contents_count')
                    ->label(__('cms.resource.contents'))
                    ->counts('contents')
                    ->sortable(),
            ])
            ->filters([
                /*
                 * Item 10 — without this the trash is unreachable: a deleted record leaves the
                 * only list that links to its edit page, so the restore action there has no route
                 * to it. Defaults to excluding deleted rows, which is what this table already did
                 * implicitly.
                 */
                TrashControls::filter(),
            ])
            ->recordActions([
                EditAction::make(),
                GuardedDeleteActions::record(),
                ...TrashControls::recordActions(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GuardedDeleteActions::bulk(),
                    ...TrashControls::bulkActions(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }
}
