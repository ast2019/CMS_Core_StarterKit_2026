<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Schemas;

use App\Enums\ContentStatus;
use App\Filament\Schemas\CmsRichEditor;
use App\Filament\Schemas\MediaAssetPicker;
use App\Filament\Schemas\SeoSection;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\Page;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PageForm
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

                /*
                 * The blueprint's §3 called this `blocks[]` while News has `body`,
                 * implying two different editors. Unified on one RichEditor storing
                 * TipTap JSON: two editors would mean two sets of custom blocks to
                 * maintain and editors switching mental models between content
                 * types. The column name stays `blocks` to match the blueprint.
                 */
                CmsRichEditor::make("blocks.{$locale}", $locale)
                    ->label(__('cms.field.blocks')),

                /*
                 * Includes the robots directive, which this form never offered.
                 * `robots_meta` has always been a translatable column on Page and
                 * robotsMetaFor() has always read it, so a static page COULD be
                 * noindex — just not from the panel. Anyone wanting to keep a
                 * thank-you or a landing page out of the index had to edit the
                 * database.
                 */
                SeoSection::make(Page::class, $locale),
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

                    TextInput::make('position')
                        ->label(__('cms.field.position'))
                        ->numeric()
                        ->default(0)
                        ->minValue(0),

                    TextInput::make('system_key')
                        ->label(__('cms.field.system_key'))
                        /*
                         * Requirement 3.8 — the 404 page is a Page record so it can
                         * be branded. Its key is shown but never editable: renaming
                         * it would orphan the page the error handler looks up, and
                         * the site would silently lose its branded 404.
                         */
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (?Page $record): bool => $record?->isSystemPage() ?? false)
                        ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),
                ]),

            Section::make(__('cms.section.featured_image'))
                ->schema([
                    MediaAssetPicker::featured()
                        ->afterStateHydrated(function (Select $component, $state, ?Page $record): void {
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
}
