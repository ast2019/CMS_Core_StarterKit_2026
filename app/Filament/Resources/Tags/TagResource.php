<?php

namespace App\Filament\Resources\Tags;

use App\Enums\PanelNavigationGroup;
use App\Filament\Concerns\SearchesTranslatedRecords;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Tags\Pages\EditTag;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Tags\Schemas\TagForm;
use App\Filament\Resources\Tags\Tables\TagsTable;
use App\Models\Tag;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class TagResource extends Resource
{
    use SearchesTranslatedRecords;

    protected static ?string $model = Tag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 21;

    /**
     * Where this resource sits in the global-search results.
     *
     * The PROPERTY, not a getGlobalSearchResultSort() method — that name is not a
     * Filament hook, so an earlier version of this was six unreachable methods and the
     * results came back in resource-registration order.
     */
    protected static ?int $globalSearchSort = 40;

    public static function getModelLabel(): string
    {
        return __('cms.resource.tag');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cms.resource.tags');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return PanelNavigationGroup::Taxonomy;
    }

    /**
     * Requirement 1.1 — a disabled module registers no Filament resource.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('cms.modules.tag', true);
    }

    public static function canAccess(): bool
    {
        return config('cms.modules.tag', true) && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return TagForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TagsTable::configure($table);
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
        return ['name'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTags::route('/'),
            'create' => CreateTag::route('/create'),
            'edit' => EditTag::route('/{record}/edit'),
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
