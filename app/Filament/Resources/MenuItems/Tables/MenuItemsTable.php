<?php

declare(strict_types=1);

namespace App\Filament\Resources\MenuItems\Tables;

use App\Models\MenuItem;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MenuItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label(__('cms.field.name'))
                    ->getStateUsing(fn (MenuItem $record): string => $record->getTranslation('label', app()->getLocale()))
                    ->description(fn (MenuItem $record): ?string => $record->parent?->getTranslation('label', app()->getLocale())),

                TextColumn::make('menu_key')
                    ->label(__('cms.field.menu_key'))
                    ->badge(),

                TextColumn::make('resolved')
                    ->label(__('cms.field.link'))
                    // Shows what the item actually resolves to for the current
                    // locale, so a broken or unpublished target is visible here
                    // rather than only on the live site.
                    ->getStateUsing(fn (MenuItem $record): string => $record->resolveUrl(app()->getLocale()) ?? '—')
                    ->extraAttributes(['class' => 'cms-ltr']),

                IconColumn::make('opens_in_new_tab')
                    ->label(__('cms.field.opens_in_new_tab'))
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('position')
                    ->label(__('cms.field.position'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('menu_key')
                    ->label(__('cms.field.menu_key'))
                    ->options([
                        'header' => 'header',
                        'footer' => 'footer',
                        'sidebar' => 'sidebar',
                    ]),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->reorderable('position')
            ->defaultSort('position');
    }
}
