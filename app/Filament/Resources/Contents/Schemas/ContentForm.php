<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contents\Schemas;

use App\Enums\ContentStatus;
use App\Enums\MediaRole;
use App\Filament\Schemas\CmsRichEditor;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\Category;
use App\Models\MediaAsset;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ContentForm
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
                    /*
                     * Slugs always render LTR regardless of locale. A Persian slug
                     * is Persian script, but it is still a URL segment: mixing it
                     * with the ASCII hyphens and any Latin fragments inside an RTL
                     * input reorders the visible segments and makes it impossible
                     * to proofread against the real URL.
                     */
                    ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                Textarea::make("excerpt.{$locale}")
                    ->label(__('cms.field.excerpt'))
                    ->rows(3)
                    ->maxLength(500)
                    ->extraInputAttributes(self::directionFor($locale)),

                CmsRichEditor::make("body.{$locale}", $locale)
                    ->label(__('cms.field.body')),

                Textarea::make("answer_paragraph.{$locale}")
                    ->label(__('cms.field.answer_paragraph'))
                    ->rows(3)
                    ->maxLength(600)
                    // GEO (blueprint §6): a self-contained answer an AI engine can
                    // quote without needing the rest of the article for context.
                    ->helperText(__('cms.field.answer_paragraph_help'))
                    ->extraInputAttributes(self::directionFor($locale)),

                Section::make(__('cms.section.seo'))
                    ->collapsed()
                    ->schema([
                        TextInput::make("meta_title.{$locale}")
                            ->label(__('cms.field.meta_title'))
                            ->maxLength(255)
                            ->helperText(__('cms.field.meta_title_help'))
                            ->extraInputAttributes(self::directionFor($locale)),

                        Textarea::make("meta_description.{$locale}")
                            ->label(__('cms.field.meta_description'))
                            ->rows(2)
                            ->maxLength(320)
                            ->helperText(__('cms.field.meta_description_help'))
                            ->extraInputAttributes(self::directionFor($locale)),

                        Select::make("robots_meta.{$locale}")
                            ->label(__('cms.field.robots_meta'))
                            ->options([
                                'index, follow' => 'index, follow',
                                'noindex, follow' => 'noindex, follow',
                                'index, nofollow' => 'index, nofollow',
                                'noindex, nofollow' => 'noindex, nofollow',
                            ])
                            ->helperText(__('cms.field.robots_meta_help')),
                    ]),
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
                        ->required()
                        ->live(),

                    DateTimePicker::make('publish_date')
                        ->label(__('cms.field.publish_date'))
                        ->seconds(false)
                        // Requirement 3.6 — a published record with a future date is
                        // scheduled, not live. Saying so here prevents the common
                        // support ticket "I published it and it is not showing".
                        ->helperText(fn (Get $get): ?string => $get('status') === ContentStatus::Published->value
                            ? __('cms.field.publish_date_help')
                            : null),

                    Select::make('primary_category_id')
                        ->label(__('cms.field.primary_category'))
                        ->relationship('primaryCategory', 'id')
                        ->getOptionLabelFromRecordUsing(
                            fn (Category $record): string => $record->getTranslation('name', app()->getLocale()),
                        )
                        ->searchable()
                        ->preload()
                        // Decision D-2: drives the canonical URL and the
                        // BreadcrumbList JSON-LD, both of which need one path.
                        ->helperText(__('cms.field.primary_category_help')),

                    Select::make('categories')
                        ->label(__('cms.field.categories'))
                        ->relationship('categories', 'id')
                        ->getOptionLabelFromRecordUsing(
                            fn (Category $record): string => $record->getTranslation('name', app()->getLocale()),
                        )
                        ->multiple()
                        ->searchable()
                        ->preload(),

                    Select::make('tags')
                        ->label(__('cms.field.tags'))
                        ->relationship('tags', 'id')
                        ->getOptionLabelFromRecordUsing(
                            fn ($record): string => $record->getTranslation('name', app()->getLocale()),
                        )
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->columnSpanFull(),
                ]),

            Section::make(__('cms.section.featured_image'))
                ->schema([
                    Select::make('featured_media_asset_id')
                        ->label(__('cms.field.featured_image'))
                        /*
                         * RULE #7 — required, and validated here rather than only in
                         * the model. The media library is the only source: an inline
                         * uploader would produce an image with no per-locale alt text
                         * (Requirement 2.7) and no library record.
                         */
                        ->required()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => MediaAsset::query()
                            ->where('type', 'image')
                            ->latest()
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (MediaAsset $a): array => [
                                $a->getKey() => $a->altTextFor(app()->getLocale()) ?: "#{$a->getKey()}",
                            ])
                            ->all())
                        ->helperText(__('cms.field.featured_image_help'))
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Select $component, $state, $record): void {
                            if ($record !== null && $state === null) {
                                $component->state($record->featuredImage()?->getKey());
                            }
                        }),
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

    /**
     * Roles the form manages, kept here so the page classes and this schema agree.
     */
    public static function featuredRole(): MediaRole
    {
        return MediaRole::Featured;
    }
}
