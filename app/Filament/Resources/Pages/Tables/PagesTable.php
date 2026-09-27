<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Tables;

use App\Enums\ContentStatus;
use App\Filament\Tables\GuardedDeleteActions;
use App\Filament\Tables\PublishingBulkActions;
use App\Filament\Tables\TrashControls;
use App\Models\Page;
use App\Services\Api\DeliveryCache;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('cms.field.title'))
                    ->getStateUsing(fn (Page $record): string => $record->getTranslation('title', app()->getLocale()))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereJsonContainsLocale('title', app()->getLocale(), "%{$search}%", 'like'))
                    /*
                     * Marks system pages so an editor understands why delete is
                     * unavailable on them (Requirement 3.8) — and, for the homepage,
                     * WHICH page currently owns /fa. Only one page can, and finding out
                     * by opening each page in turn is the tedium this line removes.
                     */
                    ->description(fn (Page $record): ?string => match (true) {
                        $record->isHomePage() => __('cms.page.homepage'),
                        $record->isSystemPage() => __('cms.field.page_role').': '.$record->system_key,
                        default => null,
                    }),

                TextColumn::make('status')
                    ->label(__('cms.field.status'))
                    ->badge()
                    ->formatStateUsing(fn (ContentStatus $state): string => $state->label())
                    ->color(fn (ContentStatus $state): string => $state->color()),

                TextColumn::make('position')
                    ->label(__('cms.field.position'))
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
            /*
             * Item 30 — the column was here and sorted by, but ordering meant typing
             * numbers into a form. reorderable() makes it drag-and-drop.
             */
            ->reorderable('position')
            /*
             * Reordering is a single UPDATE on the query builder, so no model is
             * instantiated and no observer fires — no audit row, and crucially no
             * Delivery cache invalidation, while `position` IS part of the public page
             * payload. Without this the new order is served stale until the TTL expires,
             * which is the same class of bug cms:publish-due exists to fix for scheduled
             * publishing.
             */
            ->afterReordering(fn (DeliveryCache $cache) => $cache->invalidate([
                DeliveryCache::TAG_CONTENT,
                DeliveryCache::TAG_SITEMAP,
            ]))
            ->defaultSort('position');
    }
}
