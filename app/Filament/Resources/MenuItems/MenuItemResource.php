<?php

namespace App\Filament\Resources\MenuItems;

use App\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Resources\MenuItems\Schemas\MenuItemForm;
use App\Filament\Resources\MenuItems\Tables\MenuItemsTable;
use App\Models\MenuItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MenuItemResource extends Resource
{
    protected static ?string $model = MenuItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static ?int $navigationSort = 31;

    public static function getModelLabel(): string
    {
        return __('cms.resource.menu_item');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cms.resource.menu_items');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('cms.nav.appearance');
    }

    /**
     * Requirement 1.1 — a disabled module registers no Filament resource.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('cms.modules.menu', true);
    }

    public static function canAccess(): bool
    {
        return config('cms.modules.menu', true) && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return MenuItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MenuItemsTable::configure($table);
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
            'index' => ListMenuItems::route('/'),
            'create' => CreateMenuItem::route('/create'),
            'edit' => EditMenuItem::route('/{record}/edit'),
        ];
    }

    /**
     * Resolve a record for its edit page even when it is in the trash (item 10).
     *
     * Without this the restore and force-delete actions on the edit page are DEAD: Filament
     * resolves the route binding through the model's default scope, so a soft-deleted record
     * 404s and the page carrying the only buttons that could recover it cannot be opened. The
     * actions were in the codebase and unreachable from the product.
     *
     * Trashed records are only reachable from the table's trash filter, and every action on the
     * page is still policy-gated, so widening the binding does not widen access.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
