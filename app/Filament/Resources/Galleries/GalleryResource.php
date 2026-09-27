<?php

namespace App\Filament\Resources\Galleries;

use App\Enums\PanelNavigationGroup;
use App\Filament\Concerns\SearchesTranslatedRecords;
use App\Filament\Resources\Galleries\Pages\CreateGallery;
use App\Filament\Resources\Galleries\Pages\EditGallery;
use App\Filament\Resources\Galleries\Pages\ListGalleries;
use App\Filament\Resources\Galleries\Schemas\GalleryForm;
use App\Filament\Resources\Galleries\Tables\GalleriesTable;
use App\Models\Gallery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class GalleryResource extends Resource
{
    use SearchesTranslatedRecords;

    protected static ?string $model = Gallery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 12;

    /**
     * Where this resource sits in the global-search results.
     *
     * The PROPERTY, not a getGlobalSearchResultSort() method — that name is not a
     * Filament hook, so an earlier version of this was six unreachable methods and the
     * results came back in resource-registration order.
     */
    protected static ?int $globalSearchSort = 25;

    public static function getModelLabel(): string
    {
        return __('cms.resource.gallery');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cms.resource.galleries');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return PanelNavigationGroup::Content;
    }

    /**
     * Requirement 1.1 — a disabled module registers no Filament resource.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('cms.modules.gallery', true);
    }

    public static function canAccess(): bool
    {
        return config('cms.modules.gallery', true) && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return GalleryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GalleriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * Item 21 — findable from the panel's global search.
     *
     * The search box found nothing at all before this: no resource declared any
     * searchable attribute, so it rendered and returned empty for every query. The
     * translated-column handling lives in SearchesTranslatedRecords.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGalleries::route('/'),
            'create' => CreateGallery::route('/create'),
            'edit' => EditGallery::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
