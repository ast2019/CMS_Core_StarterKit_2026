<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('cms.field.name'))
                    ->searchable(),

                TextColumn::make('email')
                    ->label(__('cms.field.email'))
                    ->searchable()
                    ->extraAttributes(['class' => 'cms-ltr']),

                TextColumn::make('role')
                    ->label(__('cms.field.role'))
                    ->badge()
                    ->formatStateUsing(fn (UserRole $state): string => $state->label()),

                IconColumn::make('is_active')
                    ->label(__('cms.field.is_active'))
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label(__('cms.audit.when'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label(__('cms.field.role'))
                    ->options(fn (): array => collect(UserRole::cases())
                        ->mapWithKeys(fn (UserRole $role): array => [$role->value => $role->label()])
                        ->all()),

                TernaryFilter::make('is_active')
                    ->label(__('cms.filter.active')),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('created_at', 'desc');
    }
}
