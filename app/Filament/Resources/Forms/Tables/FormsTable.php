<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms\Tables;

use App\Filament\Resources\Forms\Actions\DeleteFormAction;
use App\Models\Form;
use App\Support\Dates\LocalizedDate;
use Carbon\CarbonInterface;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FormsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * The submission count is loaded with the page, not per row: it is shown in a
             * column AND decides whether each row offers a delete (Form::isDeletable reads it),
             * and PanelQueryBudgetTest holds the list to a query count that does not grow with
             * its rows.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('submissions'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('cms.forms.title'))
                    ->getStateUsing(fn (Form $record): string => (string) $record->getTranslation('title', app()->getLocale()))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereJsonContainsLocale('title', app()->getLocale(), "%{$search}%", 'like')),

                TextColumn::make('key')
                    ->label(__('cms.forms.key'))
                    ->searchable()
                    ->badge()
                    ->color('gray')
                    ->extraAttributes(['class' => 'cms-ltr']),

                TextColumn::make('fields_count')
                    ->label(__('cms.forms.fields_count'))
                    ->state(fn (Form $record): string => LocalizedDate::number(count($record->fields))),

                TextColumn::make('submissions_count')
                    ->label(__('cms.forms.submissions_count'))
                    ->formatStateUsing(fn (int|string|null $state): string => LocalizedDate::number((int) $state))
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('cms.forms.is_active'))
                    ->boolean(),

                TextColumn::make('updated_at')
                    ->label(__('cms.forms.updated_at'))
                    ->formatStateUsing(fn (?CarbonInterface $state): ?string => LocalizedDate::format($state))
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),

                DeleteFormAction::make(),
            ])
            ->defaultSort('created_at');
    }
}
