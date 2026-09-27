<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms;

use App\Enums\PanelNavigationGroup;
use App\Filament\Resources\Forms\Pages\CreateForm;
use App\Filament\Resources\Forms\Pages\EditForm;
use App\Filament\Resources\Forms\Pages\ListForms;
use App\Filament\Resources\Forms\Schemas\FormForm;
use App\Filament\Resources\Forms\Tables\FormsTable;
use App\Models\Form;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Item 15 — the form builder.
 *
 * Part of the CONTACT module rather than a module of its own: the builder is the contact form
 * generalised, its submissions arrive in the contact inbox, and a site with the inbox switched
 * off has nowhere for them to go.
 */
class FormResource extends Resource
{
    protected static ?string $model = Form::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    /**
     * After Redirects, before Users: site configuration rather than content.
     */
    protected static ?int $navigationSort = 45;

    public static function getModelLabel(): string
    {
        return __('cms.resource.form');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cms.resource.forms');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return PanelNavigationGroup::System;
    }

    /**
     * Requirement 1.1 — a disabled module registers no Filament resource.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('cms.modules.contact', true);
    }

    public static function canAccess(): bool
    {
        return config('cms.modules.contact', true) && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return FormForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FormsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListForms::route('/'),
            'create' => CreateForm::route('/create'),
            'edit' => EditForm::route('/{record}/edit'),
        ];
    }
}
