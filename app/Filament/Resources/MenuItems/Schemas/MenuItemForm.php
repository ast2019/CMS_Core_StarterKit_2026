<?php

declare(strict_types=1);

namespace App\Filament\Resources\MenuItems\Schemas;

use App\Filament\Schemas\TranslatableTabs;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MenuItem;
use App\Models\Page;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class MenuItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TranslatableTabs::make(fn (string $locale, bool $isSource): array => [
                TextInput::make("label.{$locale}")
                    ->label(__('cms.field.name'))
                    ->required($isSource)
                    ->maxLength(120)
                    ->extraInputAttributes([
                        'dir' => TranslatableTabs::isRtl($locale) ? 'rtl' : 'ltr',
                        'lang' => $locale,
                    ]),
            ]),

            Section::make(__('cms.section.link'))
                ->columns(2)
                ->schema([
                    Select::make('menu_key')
                        ->label(__('cms.field.menu_key'))
                        ->options([
                            'header' => 'header',
                            'footer' => 'footer',
                            'sidebar' => 'sidebar',
                        ])
                        ->default('header')
                        ->required(),

                    Select::make('parent_id')
                        ->label(__('cms.field.parent'))
                        // Query modifier is relationship()'s third argument; Select
                        // has no ->modifyQueryUsing(). Excludes self to prevent a
                        // menu item becoming its own parent.
                        ->relationship(
                            'parent',
                            'id',
                            fn (Builder $query, ?MenuItem $record): Builder => $record === null
                                ? $query
                                : $query->whereKeyNot($record->getKey()),
                        )
                        ->getOptionLabelFromRecordUsing(
                            fn (MenuItem $record): string => $record->getTranslation('label', app()->getLocale()),
                        )
                        ->searchable(),

                    /*
                     * A menu item points either at a raw URL or at a CMS record.
                     * Linking to a record is preferable because its slug is
                     * per-locale, so one item resolves to a different path in each
                     * language — a hardcoded URL would send every locale to the
                     * Persian page.
                     */
                    Select::make('linkable_type')
                        ->label(__('cms.field.type'))
                        ->options([
                            Page::class => __('cms.resource.page'),
                            Content::class => __('cms.resource.content'),
                            Category::class => __('cms.resource.category'),
                            Gallery::class => __('cms.resource.gallery'),
                        ])
                        ->live()
                        ->placeholder(__('cms.field.link')),

                    Select::make('linkable_id')
                        ->label(__('cms.field.name'))
                        ->searchable()
                        ->visible(fn (Get $get): bool => filled($get('linkable_type')))
                        ->options(function (Get $get): array {
                            /** @var class-string|null $type */
                            $type = $get('linkable_type');

                            if (blank($type) || ! class_exists($type)) {
                                return [];
                            }

                            $field = $type === Category::class ? 'name' : 'title';

                            return $type::query()
                                ->limit(100)
                                ->get()
                                ->mapWithKeys(fn ($record): array => [
                                    $record->getKey() => (string) $record->getTranslation($field, app()->getLocale()),
                                ])
                                ->all();
                        }),

                    TextInput::make('link')
                        ->label(__('cms.field.link'))
                        ->maxLength(500)
                        ->visible(fn (Get $get): bool => blank($get('linkable_type')))
                        ->regex('/^(?!javascript:|data:|vbscript:)/i')
                        ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                    TextInput::make('position')
                        ->label(__('cms.field.position'))
                        ->numeric()
                        ->default(0)
                        ->minValue(0),

                    Toggle::make('opens_in_new_tab')
                        ->label(__('cms.field.opens_in_new_tab')),
                ]),
        ]);
    }
}
