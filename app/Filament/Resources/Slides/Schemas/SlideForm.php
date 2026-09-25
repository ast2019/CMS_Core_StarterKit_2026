<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slides\Schemas;

use App\Filament\Schemas\LinkTargetFields;
use App\Filament\Schemas\MediaAssetPicker;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\Slide;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SlideForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TranslatableTabs::make(fn (string $locale, bool $isSource): array => [
                TextInput::make("title.{$locale}")
                    ->label(__('cms.field.title'))
                    ->required($isSource)
                    ->maxLength(180)
                    ->extraInputAttributes(self::directionFor($locale)),

                TextInput::make("subtitle.{$locale}")
                    ->label(__('cms.field.subtitle'))
                    ->maxLength(300)
                    ->extraInputAttributes(self::directionFor($locale)),

                TextInput::make("cta_label.{$locale}")
                    ->label(__('cms.field.cta_label'))
                    ->maxLength(80)
                    ->extraInputAttributes(self::directionFor($locale)),
            ]),

            Section::make(__('cms.section.featured_image'))
                ->columns(2)
                ->schema([
                    // RULE #7 — slides are content-bearing (blueprint §3).
                    MediaAssetPicker::featured()
                        ->columnSpanFull()
                        ->afterStateHydrated(function (Select $component, $state, ?Slide $record): void {
                            if ($record !== null && $state === null) {
                                $component->state($record->featuredImage()?->getKey());
                            }
                        }),

                    /*
                     * Requirement 7.6 — no layout shift. Both dimensions are
                     * required because the frontend needs them to reserve space
                     * before the hero image loads; one without the other is
                     * useless, so neither is optional.
                     */
                    TextInput::make('image_width')
                        ->label(__('cms.field.image_dimensions').' — width')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->default(1920)
                        ->helperText(__('cms.field.image_dimensions_help')),

                    TextInput::make('image_height')
                        ->label(__('cms.field.image_dimensions').' — height')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->default(800),
                ]),

            Section::make(__('cms.section.link'))
                ->columns(2)
                ->schema([
                    /*
                     * The same picker the menu form offers, and for the same reason: a
                     * slide's `link` was a bare string, so the call-to-action on a
                     * Persian-authored hero sent English and Arabic visitors to the
                     * Persian page, and renaming the target's slug broke the slideshow
                     * silently. Pointing at a record resolves per locale and follows
                     * the target when it moves.
                     *
                     * targetRequired: false — unlike a menu item, a slide with no
                     * destination is a legitimate decorative hero.
                     */
                    ...LinkTargetFields::make(targetRequired: false),

                    TextInput::make('position')
                        ->label(__('cms.field.position'))
                        ->numeric()
                        ->default(0)
                        ->minValue(0),

                    Toggle::make('is_active')
                        ->label(__('cms.field.is_active'))
                        ->default(true)
                        /*
                         * Requirements 3.4, 3.5 — the cap of 5 applies to ACTIVE
                         * slides. Counting inactive ones too would stop an editor
                         * preparing next month's campaign alongside this month's,
                         * which the performance cap was never meant to prevent.
                         */
                        ->helperText(fn (): string => __('cms.validation.slides_max', [
                            'max' => Slide::maxSlides(),
                        ])),
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
