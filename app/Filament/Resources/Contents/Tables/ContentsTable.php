<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contents\Tables;

use App\Enums\ContentStatus;
use App\Enums\TranslationStatus;
use App\Filament\Tables\Columns\FeaturedImageColumn;
use App\Filament\Tables\GuardedDeleteActions;
use App\Filament\Tables\PublishingBulkActions;
use App\Filament\Tables\TrashControls;
use App\Models\Content;
use App\Models\TranslationState;
use App\Support\Dates\LocalizedDate;
use Carbon\CarbonInterface;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Everything a row reads, loaded once for the page.
             *
             * Item 50 — the thumbnail's relations. Item 37 — `translationStates`, read by the
             * translations column below through translationStatusFor(), which uses the loaded
             * relation when there is one and queries when there is not. Without it every row cost
             * one query per non-source locale: two per row at fa/en/ar, a hundred on a page of fifty,
             * on the list editors open most. HasTranslationStatus's docblock claimed this table
             * eager-loaded it; it did not, which is why nothing had noticed.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => FeaturedImageColumn::withEagerLoad($query)
                ->with('translationStates'))
            ->columns([
                FeaturedImageColumn::make(),

                TextColumn::make('title')
                    ->label(__('cms.field.title'))
                    ->getStateUsing(fn (Content $record): string => $record->getTranslation(
                        'title',
                        app()->getLocale(),
                    ))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereJsonContainsLocale('title', app()->getLocale(), "%{$search}%", 'like'))
                    ->wrap()
                    ->limit(60),

                TextColumn::make('status')
                    ->label(__('cms.field.status'))
                    ->badge()
                    ->formatStateUsing(fn (ContentStatus $state): string => $state->label())
                    ->color(fn (ContentStatus $state): string => $state->color()),

                TextColumn::make('publish_date')
                    ->label(__('cms.field.publish_date'))
                    // Rendered in the panel locale's calendar, not ->dateTime():
                    // Filament formats through Carbon, which has no Persian
                    // calendar. ->sortable() still orders by the raw UTC column,
                    // which is what keeps chronological order correct.
                    ->formatStateUsing(fn (?CarbonInterface $state): ?string => LocalizedDate::format($state))
                    ->sortable()
                    // Requirement 3.6 — a published row with a future date is
                    // scheduled. Without this the table shows "published" for
                    // something the public cannot see.
                    ->description(fn (Content $record): ?string => $record->isScheduled()
                        ? __('cms.table.scheduled')
                        : null),

                TextColumn::make('translations')
                    ->label(__('cms.field.translation_status'))
                    ->badge()
                    ->getStateUsing(function (Content $record): array {
                        $labels = [];

                        foreach ((array) config('cms.locales.supported', []) as $locale) {
                            if ($locale === $record->sourceLocale()) {
                                continue;
                            }

                            $status = $record->translationStatusFor($locale);

                            // Only surface locales needing work. Listing every
                            // locale on every row would make the column noise.
                            if ($status !== TranslationStatus::Reviewed) {
                                $labels[] = strtoupper($locale).': '.$status->label();
                            }
                        }

                        return $labels;
                    })
                    ->color('warning')
                    ->toggleable(),

                TextColumn::make('author.name')
                    ->label(__('cms.field.author'))
                    ->toggleable()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('cms.field.status'))
                    ->options(fn (): array => collect(ContentStatus::cases())
                        ->mapWithKeys(fn (ContentStatus $s): array => [$s->value => $s->label()])
                        ->all()),

                SelectFilter::make('primary_category_id')
                    ->label(__('cms.field.primary_category'))
                    ->relationship('primaryCategory', 'id')
                    ->getOptionLabelFromRecordUsing(
                        fn ($record): string => $record->getTranslation('name', app()->getLocale()),
                    )
                    ->searchable(),

                Filter::make('needs_translation')
                    ->label(__('cms.filter.needs_translation'))
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'translationStates',
                        function (Builder $inner): void {
                            /** @var Builder<TranslationState> $inner */
                            $inner->needingAttention();
                        },
                    )),

                Filter::make('scheduled')
                    ->label(__('cms.filter.scheduled'))
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<Content> $query */
                        return $query->scheduled();
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
                    // Item 23 — the content.publish ability and ContentPolicy::publish()
                    // already existed with nothing in the panel wired to them.
                    ...PublishingBulkActions::make(),
                    GuardedDeleteActions::bulk(),
                    ...TrashControls::bulkActions(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
