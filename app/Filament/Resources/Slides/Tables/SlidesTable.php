<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slides\Tables;

use App\Models\Slide;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SlidesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('position')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('title')
                    ->label(__('cms.field.title'))
                    ->getStateUsing(fn (Slide $record): string => $record->getTranslation('title', app()->getLocale()))
                    ->description(fn (Slide $record): ?string => $record->isFirstActive()
                        // Requirement 7.6 — the first active slide's image is
                        // preloaded, so which one it is matters operationally.
                        ? 'preload'
                        : null),

                IconColumn::make('is_active')
                    ->label(__('cms.field.is_active'))
                    ->boolean(),

                TextColumn::make('dimensions')
                    ->label(__('cms.field.image_dimensions'))
                    ->getStateUsing(fn (Slide $record): string => $record->image_width && $record->image_height
                        ? "{$record->image_width}×{$record->image_height}"
                        : '—')
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('active')
                    ->label(__('cms.filter.active'))
                    ->query(fn (Builder $query): Builder => $query->where('is_active', true)),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->reorderable('position')
            ->defaultSort('position');
    }
}
