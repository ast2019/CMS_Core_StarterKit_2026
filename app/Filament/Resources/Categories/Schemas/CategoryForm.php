<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Schemas;

use App\Filament\Schemas\SeoSection;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TranslatableTabs::make(fn (string $locale, bool $isSource): array => [
                TextInput::make("name.{$locale}")
                    ->label(__('cms.field.name'))
                    ->required($isSource)
                    ->maxLength(180)
                    ->extraInputAttributes(self::directionFor($locale)),

                TextInput::make("slug.{$locale}")
                    ->label(__('cms.field.slug'))
                    ->maxLength(200)
                    ->helperText(__('cms.field.slug_help'))
                    ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                Textarea::make("description.{$locale}")
                    ->label(__('cms.field.description'))
                    ->rows(3)
                    ->maxLength(1000)
                    ->extraInputAttributes(self::directionFor($locale)),

                // Category archives are sitemap entries too, so they get the same
                // robots directive the other three resources now have.
                SeoSection::make(Category::class, $locale),
            ]),

            Section::make(__('cms.section.publishing'))
                ->columns(2)
                ->schema([
                    Select::make('parent_id')
                        ->label(__('cms.field.parent'))
                        /*
                         * The query modifier is the THIRD argument to
                         * relationship(), not a ->modifyQueryUsing() call — Select
                         * has no such method.
                         *
                         * It excludes the record itself from its own parent list. A
                         * category that is its own parent creates a cycle; the depth
                         * guard in Category::ancestors() stops the request hanging,
                         * but the breadcrumb trail would still be nonsense.
                         */
                        ->relationship(
                            'parent',
                            'id',
                            fn (Builder $query, ?Category $record): Builder => $record === null
                                ? $query
                                : $query->whereKeyNot($record->getKey()),
                        )
                        ->getOptionLabelFromRecordUsing(
                            fn (Category $record): string => $record->getTranslation('name', app()->getLocale()),
                        )
                        ->searchable()
                        ->preload(),

                    TextInput::make('position')
                        ->label(__('cms.field.position'))
                        ->numeric()
                        ->default(0)
                        ->minValue(0),
                ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function directionFor(string $locale): array
    {
        return [
            'dir' => TranslatableTabs::isRtl($locale) ? 'rtl' : 'ltr',
            'lang' => $locale,
        ];
    }
}
