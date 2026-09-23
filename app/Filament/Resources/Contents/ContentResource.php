<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contents;

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

class ContentResource extends Resource
{
    protected static ?string $model = Content::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('cms.resource.content');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cms.resource.contents');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('cms.nav.content');
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

    public static function getPages(): array
    {
        return [
            'index' => ListContents::route('/'),
            'create' => CreateContent::route('/create'),
            'edit' => EditContent::route('/{record}/edit'),
        ];
    }
}
