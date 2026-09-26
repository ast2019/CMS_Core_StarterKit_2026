<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contents\Tables;

use App\Enums\ContentStatus;
use App\Enums\TranslationStatus;
use App\Models\Content;
use App\Models\TranslationState;
use App\Support\Dates\LocalizedDate;
use Carbon\CarbonInterface;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
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
            ->columns([
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
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
