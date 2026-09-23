<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Tables;

use App\Models\Category;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('cms.field.name'))
                    ->getStateUsing(fn (Category $record): string => $record->getTranslation('name', app()->getLocale()))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereJsonContainsLocale('name', app()->getLocale(), "%{$search}%", 'like'))
                    // Shows nesting without a tree component: a flat list of
                    // identically-styled names hides the hierarchy that drives
                    // breadcrumbs and canonical URLs.
                    ->description(fn (Category $record): ?string => $record->parent === null
                        ? null
                        : $record->parent->getTranslation('name', app()->getLocale())),

                TextColumn::make('slug')
                    ->label(__('cms.field.slug'))
                    ->getStateUsing(fn (Category $record): string => (string) $record->getTranslation('slug', app()->getLocale()))
                    ->extraAttributes(['class' => 'cms-ltr'])
                    ->toggleable(),

                TextColumn::make('contents_count')
                    ->label(__('cms.resource.contents'))
                    ->counts('contents')
                    ->sortable(),

                TextColumn::make('position')
                    ->label(__('cms.field.position'))
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('position');
    }
}
