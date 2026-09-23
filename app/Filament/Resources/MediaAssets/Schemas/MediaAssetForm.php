<?php

declare(strict_types=1);

namespace App\Filament\Resources\MediaAssets\Schemas;

use App\Filament\Schemas\TranslatableTabs;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MediaAssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('cms.section.media'))
                ->schema([
                    Select::make('type')
                        ->label(__('cms.field.type'))
                        ->options([
                            'image' => __('cms.field.type').': image',
                            'video' => __('cms.field.type').': video',
                            'document' => __('cms.field.type').': document',
                        ])
                        ->default('image')
                        ->required()
                        ->live(),

                    /*
                     * RULE #9 — the upload lands on the local `public` disk, which
                     * comes from config/media-library.php rather than being named
                     * here, so the rule has one enforcement point.
                     */
                    SpatieMediaLibraryFileUpload::make('file')
                        ->label(__('cms.field.file'))
                        ->collection('file')
                        ->disk(config('cms.media.disk', 'public'))
                        ->maxSize(10 * 1024)
                        ->downloadable()
                        ->openable()
                        ->columnSpanFull(),

                    TextInput::make('external_embed_url')
                        ->label(__('cms.field.external_embed_url'))
                        ->url()
                        ->maxLength(500)
                        ->visible(fn (Get $get): bool => $get('type') === 'video'),

                    /*
                     * Decision D-6. Google's video sitemap spec requires a
                     * thumbnail; duration is only recommended.
                     *
                     * When ffprobe is installed, App\Listeners\ExtractVideoMetadata
                     * fills the duration and dimensions from the uploaded file shortly
                     * after the save, on the queue. It never overwrites a value typed
                     * here, so a correction survives. Where ffprobe is absent these
                     * fields stay manual, and the publishing rule still demands a
                     * thumbnail for locally hosted video either way — a thumbnail
                     * cannot be derived without ffmpeg at all.
                     */
                    SpatieMediaLibraryFileUpload::make('video_thumbnail')
                        ->label(__('cms.field.video_thumbnail'))
                        ->collection('video_thumbnail')
                        ->disk(config('cms.media.disk', 'public'))
                        ->image()
                        ->helperText(__('cms.field.video_thumbnail_help'))
                        ->visible(fn (Get $get): bool => $get('type') === 'video'),

                    TextInput::make('duration_seconds')
                        ->label(__('cms.field.duration_seconds'))
                        ->numeric()
                        ->minValue(0)
                        ->visible(fn (Get $get): bool => $get('type') === 'video'),
                ]),

            TranslatableTabs::make(fn (string $locale, bool $isSource): array => [
                Textarea::make("alt_text.{$locale}")
                    ->label(__('cms.field.alt_text'))
                    // Requirement 2.7 — mandatory in the source locale. Alt text is
                    // the one field that cannot be fixed later at scale: nobody
                    // revisits a 2000-image library to describe it retroactively.
                    ->required($isSource)
                    ->rows(2)
                    ->maxLength(300)
                    ->helperText(__('cms.field.alt_text_help'))
                    ->extraInputAttributes(self::directionFor($locale)),

                Textarea::make("caption.{$locale}")
                    ->label(__('cms.field.caption'))
                    ->rows(2)
                    ->maxLength(500)
                    ->extraInputAttributes(self::directionFor($locale)),
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
