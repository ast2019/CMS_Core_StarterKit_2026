<?php

declare(strict_types=1);

namespace App\Filament\Resources\Galleries\Schemas;

use App\Enums\ContentStatus;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\Gallery;
use App\Models\MediaAsset;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GalleryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TranslatableTabs::make(fn (string $locale, bool $isSource): array => [
                TextInput::make("title.{$locale}")
                    ->label(__('cms.field.title'))
                    ->required($isSource)
                    ->maxLength(255)
                    ->extraInputAttributes(self::directionFor($locale)),

                TextInput::make("slug.{$locale}")
                    ->label(__('cms.field.slug'))
                    ->maxLength(255)
                    ->helperText(__('cms.field.slug_help'))
                    ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                Textarea::make("description.{$locale}")
                    ->label(__('cms.field.description'))
                    ->rows(3)
                    ->maxLength(1000)
                    ->extraInputAttributes(self::directionFor($locale)),
            ]),

            Section::make(__('cms.section.publishing'))
                ->columns(2)
                ->schema([
                    Select::make('status')
                        ->label(__('cms.field.status'))
                        ->options(fn (): array => collect(ContentStatus::cases())
                            ->mapWithKeys(fn (ContentStatus $s): array => [$s->value => $s->label()])
                            ->all())
                        ->default(ContentStatus::Draft->value)
                        ->required(),

                    DateTimePicker::make('publish_date')
                        ->label(__('cms.field.publish_date'))
                        ->seconds(false),
                ]),

            /*
             * Decision D-4: the cover is the single `featured` attachment and is
             * what RULE #7 governs; the items are a separate, uncapped
             * `gallery`-role collection. Keeping them in distinct sections makes
             * that distinction visible, rather than leaving an editor to guess
             * which image becomes the card thumbnail.
             */
            Section::make(__('cms.section.featured_image'))
                ->description(__('cms.field.featured_image_help'))
                ->schema([
                    Select::make('featured_media_asset_id')
                        ->label(__('cms.field.featured_image'))
                        ->required()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => self::imageOptions())
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Select $component, $state, ?Gallery $record): void {
                            if ($record !== null && $state === null) {
                                $component->state($record->cover()?->getKey());
                            }
                        }),
                ]),

            Section::make(__('cms.resource.gallery'))
                ->schema([
                    Select::make('gallery_item_ids')
                        ->label(__('cms.resource.media_assets'))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => self::imageOptions(200))
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Select $component, $state, ?Gallery $record): void {
                            if ($record !== null && $state === null) {
                                $component->state($record->items()->pluck('media_assets.id')->all());
                            }
                        }),
                ]),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private static function imageOptions(int $limit = 50): array
    {
        return MediaAsset::query()
            ->where('type', 'image')
            ->latest()
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (MediaAsset $a): array => [
                $a->getKey() => $a->altTextFor(app()->getLocale()) ?: "#{$a->getKey()}",
            ])
            ->all();
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
