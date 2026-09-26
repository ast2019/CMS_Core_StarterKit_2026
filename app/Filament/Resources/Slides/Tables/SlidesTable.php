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
            // The link column resolves the morph, so without this the list costs an
            // extra query per slide.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('linkable'))
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

                TextColumn::make('resolved')
                    ->label(__('cms.field.link'))
                    /*
                     * What the slide actually resolves to in the current locale, as the
                     * menu table already showed. A slide pointing at a deleted,
                     * unpublished or disabled-module target is dropped from the public
                     * payload, and the dash here is the only place an editor can see
                     * that before a visitor does.
                     */
                    ->getStateUsing(fn (Slide $record): string => $record->resolveUrl(app()->getLocale()) ?? '—')
                    ->extraAttributes(['class' => 'cms-ltr'])
                    ->toggleable(),

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
