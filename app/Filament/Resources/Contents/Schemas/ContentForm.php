<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contents\Schemas;

use App\Enums\ArticleSchemaType;
use App\Enums\ContentStatus;
use App\Enums\MediaRole;
use App\Filament\Schemas\CmsRichEditor;
use App\Filament\Schemas\MediaAssetPicker;
use App\Filament\Schemas\SeoSection;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\Category;
use App\Models\Content;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
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

                SeoSection::make(Content::class, $locale),
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

                    /*
                     * Requirement 7.3 — the schema.org type this article is published
                     * AS. In the publishing section rather than in the SEO tabs
                     * because it is not translatable: the same guide cannot be a
                     * NewsArticle in Persian and a BlogPosting in English without
                     * contradicting itself across the hreflang cluster.
                     */
                    /*
                     * A Radio rather than a Select, because Filament only supports
                     * per-option descriptions on a Radio — and the descriptions ARE
                     * the feature. "NewsArticle" versus "Article" means nothing to an
                     * editor without the sentence explaining that one promises recency;
                     * a bare three-item dropdown would be picked at random, and a
                     * randomly chosen schema type is worse than the hardcoded one it
                     * replaced.
                     */
                    Radio::make('schema_type')
                        ->label(__('cms.field.schema_type'))
                        ->options(fn (): array => collect(ArticleSchemaType::cases())
                            ->mapWithKeys(fn (ArticleSchemaType $t): array => [$t->value => $t->label()])
                            ->all())
                        ->descriptions(fn (): array => collect(ArticleSchemaType::cases())
                            ->mapWithKeys(fn (ArticleSchemaType $t): array => [$t->value => $t->description()])
                            ->all())
                        ->default(ArticleSchemaType::default()->value)
                        ->required()
                        ->columnSpanFull()
                        ->helperText(__('cms.field.schema_type_help')),

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
                    /*
                     * RULE #7 — required, and validated here rather than only in
                     * the model. The picker offers the library AND an inline
                     * uploader, so attaching a new image no longer means abandoning
                     * a half-written article to visit the Media section; see
                     * MediaAssetPicker for why that stays compatible with
                     * Requirement 2.7 and Decision D-3.
                     */
                    MediaAssetPicker::featured()
                        ->afterStateHydrated(function (Select $component, $state, ?Content $record): void {
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
