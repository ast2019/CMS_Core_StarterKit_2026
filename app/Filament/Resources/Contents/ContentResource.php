<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contents;

use App\Enums\PanelNavigationGroup;
use App\Filament\Concerns\SearchesTranslatedRecords;
use App\Filament\Resources\Contents\Pages\CreateContent;
use App\Filament\Resources\Contents\Pages\EditContent;
use App\Filament\Resources\Contents\Pages\ListContents;
use App\Filament\Resources\Contents\Schemas\ContentForm;
use App\Filament\Resources\Contents\Tables\ContentsTable;
use App\Models\Content;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ContentResource extends Resource
{
    use SearchesTranslatedRecords;

    protected static ?string $model = Content::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?int $navigationSort = 10;

    /**
     * Where this resource sits in the global-search results.
     *
     * The PROPERTY, not a getGlobalSearchResultSort() method — that name is not a
     * Filament hook, so an earlier version of this was six unreachable methods and the
     * results came back in resource-registration order.
     */
    protected static ?int $globalSearchSort = 10;

    public static function getModelLabel(): string
    {
        return __('cms.resource.content');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cms.resource.contents');
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
        return (bool) config('cms.modules.content', true);
    }

    public static function canAccess(): bool
    {
        return config('cms.modules.content', true) && parent::canAccess();
    }

    /**
     * Surfaces the translation backlog on the navigation item, so the work is
     * visible without opening the resource (Requirement 5.3).
     */
    public static function getNavigationBadge(): ?string
    {
        $count = Content::query()
            ->whereHas('translationStates', fn ($query) => $query->needingAttention())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return ContentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
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
        return ['title', 'excerpt'];
    }

    /**
     * @return array<string, string|null>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Content $record */
        return [
            __('cms.field.status') => $record->status->label(),
            __('cms.field.primary_category') => $record->primaryCategory?->getTranslation('name', app()->getLocale()),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContents::route('/'),
            'create' => CreateContent::route('/create'),
            'edit' => EditContent::route('/{record}/edit'),
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
