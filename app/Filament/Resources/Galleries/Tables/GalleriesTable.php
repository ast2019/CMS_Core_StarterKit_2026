<?php

declare(strict_types=1);

namespace App\Filament\Resources\Galleries\Tables;

use App\Enums\ContentStatus;
use App\Filament\Tables\Columns\FeaturedImageColumn;
use App\Filament\Tables\GuardedDeleteActions;
use App\Filament\Tables\PublishingBulkActions;
use App\Filament\Tables\TrashControls;
use App\Models\Gallery;
use App\Support\Dates\LocalizedDate;
use Carbon\CarbonInterface;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GalleriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Item 50 — the thumbnail's relations, so fifty rows cost one query rather than a hundred.
            /*
             * Item 39 — `items_count` as a subquery on the page's own SELECT, rather than
             * Gallery::itemCount() per row. Same relation, so the same rules apply: the gallery role
             * only, and a trashed asset is not counted.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => FeaturedImageColumn::withEagerLoad($query)
                ->withCount('items'))
            ->columns([
                FeaturedImageColumn::make(),

                TextColumn::make('title')
                    ->label(__('cms.field.title'))
                    ->getStateUsing(fn (Gallery $record): string => $record->getTranslation('title', app()->getLocale()))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereJsonContainsLocale('title', app()->getLocale(), "%{$search}%", 'like')),

                TextColumn::make('status')
                    ->label(__('cms.field.status'))
                    ->badge()
                    ->formatStateUsing(fn (ContentStatus $state): string => $state->label())
                    ->color(fn (ContentStatus $state): string => $state->color()),

                TextColumn::make('items_count')
                    ->label(__('cms.table.items'))
                    ->formatStateUsing(fn (?int $state): string => LocalizedDate::number((int) $state))
                    ->sortable(),

                TextColumn::make('publish_date')
                    ->label(__('cms.field.publish_date'))
                    ->formatStateUsing(fn (?CarbonInterface $state): ?string => LocalizedDate::format($state, 'date'))
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('cms.field.status'))
                    ->options(fn (): array => collect(ContentStatus::cases())
                        ->mapWithKeys(fn (ContentStatus $s): array => [$s->value => $s->label()])
                        ->all()),

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
                    // Item 23 — the content.publish ability and the policy methods
                    // already existed with nothing in the panel wired to them.
                    ...PublishingBulkActions::make(),
                    GuardedDeleteActions::bulk(),
                    ...TrashControls::bulkActions(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
