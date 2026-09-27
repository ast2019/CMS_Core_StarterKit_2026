<?php

namespace App\Filament\Resources\Pages;

use App\Enums\PanelNavigationGroup;
use App\Filament\Concerns\SearchesTranslatedRecords;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Filament\Resources\Pages\Tables\PagesTable;
use App\Models\Page;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class PageResource extends Resource
{
    use SearchesTranslatedRecords;

    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 11;

    /**
     * Where this resource sits in the global-search results.
     *
     * The PROPERTY, not a getGlobalSearchResultSort() method — that name is not a
     * Filament hook, so an earlier version of this was six unreachable methods and the
     * results came back in resource-registration order.
     */
    protected static ?int $globalSearchSort = 20;

    public static function getModelLabel(): string
    {
        return __('cms.resource.page');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cms.resource.pages');
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
        return (bool) config('cms.modules.page', true);
    }

    public static function canAccess(): bool
    {
        return config('cms.modules.page', true) && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return PageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PagesTable::configure($table);
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

    /**
     * @return array<string, string|null>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Page $record */
        return [
            __('cms.field.status') => $record->status->label(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
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
