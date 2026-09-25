<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * User management (Requirement 9.1).
 *
 * Access is Admin-only, but there is no per-module config flag here as there is
 * for the optional modules — user management is not a toggleable feature. Laravel
 * auto-discovers App\Policies\UserPolicy for App\Models\User, so Filament's
 * default authorization already enforces `user.manage` on every page; adding a
 * canAccess() gate would only duplicate the policy.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 50;

    public static function getModelLabel(): string
    {
        return __('cms.resource.user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cms.resource.users');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('cms.nav.system');
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
