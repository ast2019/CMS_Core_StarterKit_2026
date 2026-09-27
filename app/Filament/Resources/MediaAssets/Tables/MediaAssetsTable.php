<?php

declare(strict_types=1);

namespace App\Filament\Resources\MediaAssets\Tables;

use App\Filament\Tables\GuardedDeleteActions;
use App\Filament\Tables\TrashControls;
use App\Models\MediaAsset;
use Filament\Actions\BulkActionGroup;
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
                    ->label(__('cms.field.preview'))
                    ->collection('file')
                    ->conversion('thumb'),

                TextColumn::make('alt_text')
                    ->label(__('cms.field.alt_text'))
                    ->getStateUsing(fn (MediaAsset $record): string => $record->altTextFor(app()->getLocale()))
                    ->placeholder(__('cms.table.no_alt_text'))
                    /*
                     * Item 22 — the library had NO searchable column, so finding one
                     * image among thousands meant paging. Alt text is the right thing to
                     * search: Requirement 2.7 makes it mandatory, so every asset has one,
                     * and it describes the picture in the editor's own words.
                     */
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereJsonContainsLocale('alt_text', app()->getLocale(), "%{$search}%", 'like'))
                    ->wrap()
                    ->limit(70),

                TextColumn::make('type')
                    ->label(__('cms.field.type'))
                    ->formatStateUsing(fn (?string $state): ?string => $state === null
                        ? null
                        : (MediaAsset::typeOptions()[$state] ?? $state))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'image' => 'success',
                        'video' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('size')
                    ->label(__('cms.media.size'))
                    ->getStateUsing(fn (MediaAsset $record): ?string => $record->humanSize())
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('uploader.name')
                    ->label(__('cms.field.author'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('cms.field.type'))
                    ->options(fn (): array => MediaAsset::typeOptions()),

                Filter::make('missing_alt_text')
                    ->label(__('cms.filter.missing_alt_text'))
                    // Requirement 2.7 — surfaced as a filter so the backlog is
                    // actionable rather than discovered at publish time.
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<MediaAsset> $query */
                        return $query->missingAltText();
                    }),

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
            ->defaultSort('created_at', 'desc');
    }
}
