<?php

namespace App\Filament\Resources\Slides;

use App\Filament\Resources\Slides\Pages\CreateSlide;
use App\Filament\Resources\Slides\Pages\EditSlide;
use App\Filament\Resources\Slides\Pages\ListSlides;
use App\Filament\Resources\Slides\Schemas\SlideForm;
use App\Filament\Resources\Slides\Tables\SlidesTable;
use App\Models\Slide;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SlideResource extends Resource
{
    protected static ?string $model = Slide::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('cms.resource.slide');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cms.resource.slides');
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
        return (bool) config('cms.modules.slide', true);
    }

    public static function canAccess(): bool
    {
        return config('cms.modules.slide', true) && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return SlideForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SlidesTable::configure($table);
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
            'index' => ListSlides::route('/'),
            'create' => CreateSlide::route('/create'),
            'edit' => EditSlide::route('/{record}/edit'),
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
