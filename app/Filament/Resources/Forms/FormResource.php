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
use App\Services\Forms\ContactFormStructure;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Item 15 — the form builder.
 *
 * Two switches, nested: `cms.modules.contact` (the contact form and the inbox) and, under it,
 * `cms.modules.forms` (building other forms). Read the second only through
 * Form::builderEnabled().
 *
 * With the contact module on and forms off, this screen still opens, but lists and edits ONLY
 * the built-in contact form: since 0.9.0 its wording lives nowhere else (GET /api/v1/contact
 * serves `form_labels` from it), so hiding the screen would freeze that text. No other form
 * can be created, listed or opened. With contact off, nothing here exists.
 */
class FormResource extends Resource
{
    protected static ?string $model = Form::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    /**
     * After Redirects, before Users, in the System group — where the owner found it. It is
     * editorial copy (see UserRole, `form.manage`), but moving the menu entry is not part of that.
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

    /**
     * With the builder off, only the contact form is reachable — in the list and by URL, since
     * Filament resolves the edit page's record through this query.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return Form::builderEnabled()
            ? $query
            : $query->where('key', ContactFormStructure::KEY);
    }

    public static function canCreate(): bool
    {
        return Form::builderEnabled() && parent::canCreate();
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
