<?php

declare(strict_types=1);

namespace App\Filament\Resources\MediaAssets\Tables;

use App\Models\MediaAsset;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MediaAssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('preview')
                    ->label('')
                    ->collection('file')
                    ->conversion('thumb'),

                TextColumn::make('alt_text')
                    ->label(__('cms.field.alt_text'))
                    ->getStateUsing(fn (MediaAsset $record): string => $record->altTextFor(app()->getLocale()))
                    ->placeholder(__('cms.table.no_alt_text'))
                    ->wrap()
                    ->limit(70),

                TextColumn::make('type')
                    ->label(__('cms.field.type'))
                    ->badge(),

                TextColumn::make('size')
                    ->label('KB')
                    ->getStateUsing(fn (MediaAsset $record): ?string => $record->size === null
                        ? null
                        : number_format($record->size / 1024, 0))
                    ->toggleable(),

                TextColumn::make('uploader.name')
                    ->label(__('cms.field.author'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('cms.field.type'))
                    ->options([
                        'image' => 'image',
                        'video' => 'video',
                        'document' => 'document',
                    ]),

                Filter::make('missing_alt_text')
                    ->label(__('cms.filter.missing_alt_text'))
                    // Requirement 2.7 — surfaced as a filter so the backlog is
                    // actionable rather than discovered at publish time.
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<MediaAsset> $query */
                        return $query->missingAltText();
                    }),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
