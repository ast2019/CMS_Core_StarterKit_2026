<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Tables;

use App\Enums\RedirectType;
use App\Models\Redirect;
use App\Support\Dates\LocalizedDate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RedirectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_path')
                    ->label(__('cms.field.from_path'))
                    ->searchable()
                    ->extraAttributes(['class' => 'cms-ltr']),

                TextColumn::make('to_path')
                    ->label(__('cms.field.to_path'))
                    ->searchable()
                    ->extraAttributes(['class' => 'cms-ltr']),

                TextColumn::make('type')
                    ->label(__('cms.field.redirect_type'))
                    ->badge()
                    ->formatStateUsing(fn (RedirectType $state): string => $state->label()),

                TextColumn::make('hits')
                    ->label(__('cms.field.hits'))
                    ->sortable()
                    // Makes dead redirects visible so the table can be pruned on
                    // evidence rather than guesswork.
                    //
                    // Relative time rather than a date, so it stays readable next
                    // to the hit count. LocalizedDate::human() routes through
                    // Carbon's own Persian phrasing and then converts the digits,
                    // which Carbon leaves in ASCII whatever the locale.
                    ->description(fn (Redirect $record): ?string => LocalizedDate::human($record->last_hit_at)),

                TextColumn::make('source_type')
                    ->label(__('cms.field.author'))
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? 'manual'
                        : 'auto: '.class_basename($state))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('cms.field.redirect_type'))
                    ->options(fn (): array => collect(RedirectType::cases())
                        ->mapWithKeys(fn (RedirectType $t): array => [$t->value => $t->label()])
                        ->all()),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
