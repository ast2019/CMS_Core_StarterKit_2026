<?php

declare(strict_types=1);

namespace App\Filament\Resources\MediaAssets\Schemas;

use App\Filament\Resources\MediaAssets\MediaUsageList;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\MediaAsset;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
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
                        ->options(fn (): array => MediaAsset::typeOptions())
                        ->default('image')
                        ->required()
                        ->live()
                        /*
                         * Item 12 — on an existing asset the file is already there, so a new type
                         * must accept it: turning a PDF into an "image" would send it to the image
                         * conversions and to every image slot in the API. The model refuses the same
                         * change for any other writer.
                         */
                        ->rule(fn (?MediaAsset $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            // Only a CHANGE of type, as in the model guard: a row stored before the
                            // allowlists existed must stay editable (alt text, caption) as it is.
                            if ($record === null || (string) $value === (string) $record->type) {
                                return;
                            }

                            $mime = $record->storedMimeType();

                            if ($mime !== null && ! MediaAsset::typeAccepts((string) $value, $mime)) {
                                $fail(__('cms.media.validation.type_mismatch', [
                                    'type' => MediaAsset::typeOptions()[(string) $value] ?? (string) $value,
                                    'mime' => $mime,
                                ]));
                            }
                        }),

                    /*
                     * RULE #9 — the upload lands on the local `public` disk, which
                     * comes from config/media-library.php rather than being named
                     * here, so the rule has one enforcement point.
                     *
                     * Item 12 — the accepted types follow the chosen asset type and are checked
                     * against the file's content on the server (Filament's `mimetypes` rule), so a
                     * renamed .exe is refused whatever it is called.
                     *
                     * Locked once the asset exists. Swapping the file changes it on every record
                     * that uses it, so it goes through the "Replace file" action, which says where
                     * first; a silent swap from this field skipped that warning entirely.
                     */
                    SpatieMediaLibraryFileUpload::make('file')
                        ->label(__('cms.field.file'))
                        ->collection('file')
                        ->disk(config('cms.media.disk', 'public'))
                        ->acceptedFileTypes(fn (Get $get): array => MediaAsset::mimeTypesFor($get('type')))
                        ->rule(fn (Get $get): Closure => MediaAsset::fileContentRule($get('type')))
                        ->maxSize(10 * 1024)
                        ->downloadable()
                        ->openable()
                        ->disabledOn('edit')
                        ->deletable(fn (string $operation): bool => $operation !== 'edit')
                        ->helperText(fn (string $operation): ?string => $operation === 'edit'
                            ? __('cms.media.replace.locked_hint')
                            : null)
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
                        // The image list, not ->image(): that accepts image/*, SVG included.
                        ->acceptedFileTypes(MediaAsset::mimeTypesFor('image'))
                        ->rule(fn (): Closure => MediaAsset::fileContentRule('image'))
                        ->helperText(__('cms.field.video_thumbnail_help'))
                        ->visible(fn (Get $get): bool => $get('type') === 'video'),

                    TextInput::make('duration_seconds')
                        ->label(__('cms.field.duration_seconds'))
                        ->numeric()
                        ->minValue(0)
                        ->visible(fn (Get $get): bool => $get('type') === 'video'),
                ]),

            /*
             * Item 12 — where this asset is used, record by record, with links. On the asset's own
             * page because that is where the question gets asked: before replacing the file, before
             * rewriting the alt text that every one of those records inherits, before deleting.
             */
            Section::make(__('cms.media.usage.heading'))
                ->description(__('cms.media.usage.caveat'))
                ->visibleOn('edit')
                ->schema([
                    View::make('filament.media.usage')
                        ->viewData(fn (?MediaAsset $record): array => $record === null
                            ? ['usage' => null]
                            : ['usage' => MediaUsageList::for($record)]),
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
