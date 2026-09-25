<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Models\MediaAsset;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The image picker used by every content-bearing form, with an inline uploader.
 *
 * RULE #7, RULE #8, RULE #9 — Requirements 2.4, 2.7, 3.2, 3.3.
 *
 * ---------------------------------------------------------------------------
 * Why this class exists
 * ---------------------------------------------------------------------------
 * Four forms (Content, Page, Slide, Gallery) plus the gallery item list carried
 * a copy-pasted `Select` over the media library. Attaching an image therefore
 * meant leaving the half-written article, creating the asset in the Media
 * section, and coming back to a form that had lost its unsaved state. That is
 * the workflow complaint this class answers, and answering it in five places
 * independently would have meant five chances to drop one of the invariants
 * below.
 *
 * ---------------------------------------------------------------------------
 * Why createOptionForm() and not a header Action
 * ---------------------------------------------------------------------------
 * A separate `Action` with its own `->schema()` (the pattern used by
 * ManagesContentVersions) would need to write the chosen id back into the form
 * state by hand, and would leave the field itself looking unchanged. Filament's
 * own create-option modal already does exactly what is wanted: it validates its
 * nested schema, calls createOptionUsing(), and selects the returned key. That
 * matters for the alt-text invariant in particular — Requirement 2.7 is enforced
 * by `->required($isSource)` on the real field inside the modal, not by a rule
 * reimplemented next to it.
 *
 * `->relationship()` is deliberately not used: there is no `featuredMediaAsset`
 * BelongsTo to hang it off. The link is the polymorphic `media_attachments`
 * pivot (Decision D-3), which is why the field stays `dehydrated(false)` and
 * ManagesFeaturedImage applies it after the record exists.
 *
 * ---------------------------------------------------------------------------
 * What the inline path must not break
 * ---------------------------------------------------------------------------
 *  - A real MediaAsset row is created (Decision D-3), so the image stays
 *    reusable, describable and visible to the media module and the Delivery API.
 *    Notably NOT a SpatieMediaLibraryFileUpload bound to Content/Page/Slide:
 *    those models are not HasMedia, and binding the upload to them would put the
 *    file outside the library entirely.
 *  - Source-locale alt text stays mandatory (Requirement 2.7), and is checked
 *    again in createAsset() so the invariant does not live only in a schema.
 *  - The disk comes from config (RULE #9), never from a literal.
 *  - The asset is created through Eloquent, so IsAuditable logs it (RULE #8).
 *  - Uploading is authorized against MediaAssetPolicy::create, so a user who may
 *    edit an article but not upload media does not gain an upload path here.
 */
final class MediaAssetPicker
{
    /**
     * The field name is part of the contract: ManagesFeaturedImage reads it out
     * of the raw form state, and the resource tests fill it by id.
     */
    public const FEATURED_FIELD = 'featured_media_asset_id';

    /**
     * The gallery item list, read back by ManagesGalleryItems.
     *
     * Both names live on this class rather than on the traits that consume them
     * because a trait constant cannot be read through the trait's name — and the
     * schema is the side that owns the field name anyway.
     */
    public const GALLERY_ITEMS_FIELD = 'gallery_item_ids';

    /**
     * The single featured image / gallery cover.
     *
     * Required on every content-bearing model, which is the "at least one" half
     * of RULE #7; the "exactly one" half is enforced on the write path by
     * HasFeaturedImage::attachMediaAsset().
     */
    public static function featured(?string $helperText = null): Select
    {
        return self::image(self::FEATURED_FIELD)
            ->label(__('cms.field.featured_image'))
            ->required()
            ->helperText($helperText ?? __('cms.field.featured_image_help'));
    }

    /**
     * An image chosen from the library, with the option to upload a new one.
     */
    public static function image(string $name, int $optionLimit = 50): Select
    {
        return self::configure(Select::make($name), $optionLimit);
    }

    /**
     * Several images, in the order the editor arranged them.
     */
    public static function images(string $name, int $optionLimit = 200): Select
    {
        return self::configure(Select::make($name)->multiple(), $optionLimit);
    }

    private static function configure(Select $select, int $optionLimit): Select
    {
        return $select
            ->searchable()
            ->preload()
            ->options(fn (): array => self::options($optionLimit))
            /*
             * The ->options() closure is resolved once per request, so an asset
             * created a moment ago by the modal is not in it and the field would
             * render a bare id with no label. These two resolve a label for any
             * key, whether or not it was in the memoised list.
             */
            ->getOptionLabelUsing(fn ($value): ?string => self::labelFor($value))
            ->getOptionLabelsUsing(fn (array $values): array => self::labelsFor($values))
            /*
             * Not a column on the record: the attachment is a row in
             * media_attachments, applied after the save. See ManagesFeaturedImage
             * and ManagesGalleryItems.
             */
            ->dehydrated(false)
            ->createOptionForm(fn (): array => self::uploadSchema())
            ->createOptionAction(fn (Action $action): Action => $action
                ->label(__('cms.media.inline_upload'))
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading(__('cms.media.inline_upload_heading'))
                ->modalDescription(__('cms.media.inline_upload_description'))
                ->modalSubmitActionLabel(__('cms.media.inline_upload_submit'))
                /*
                 * Hides the uploader for a user who may edit content but holds no
                 * `media.upload` ability. Without this the content form would be a
                 * way around MediaAssetPolicy::create.
                 */
                ->authorize(fn (): bool => self::canUpload()))
            ->createOptionUsing(fn (array $data): int => self::createAsset($data));
    }

    /**
     * Whether the current user may add to the media library at all.
     */
    public static function canUpload(): bool
    {
        return Auth::user()?->can('create', MediaAsset::class) ?? false;
    }

    /**
     * Create a library asset from the inline modal's payload and return its key.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createAsset(array $data): int
    {
        /*
         * Re-checked here rather than trusting the hidden action. The modal is
         * reachable over the wire by anyone who can render the form, and this is
         * the method that actually writes.
         */
        abort_unless(self::canUpload(), 403);

        $altText = self::localeMap($data['alt_text'] ?? []);
        $source = (string) config('cms.locales.source', 'fa');

        /*
         * Requirement 2.7, defended twice. The modal marks the source-locale field
         * required, but alt text is the one field that cannot be fixed later at
         * scale — nobody revisits a 2000-image library to describe it — so the
         * write path refuses rather than assuming the schema was not bypassed.
         */
        if (blank($altText[$source] ?? null)) {
            throw ValidationException::withMessages([
                "alt_text.{$source}" => __('cms.validation.alt_text_required'),
            ]);
        }

        $path = self::uploadedPath($data['file'] ?? null);

        if ($path === null) {
            throw ValidationException::withMessages([
                'file' => __('cms.validation.media_file_required'),
            ]);
        }

        // Eloquent create(), so IsAuditable records the upload (RULE #8). The
        // uploader is stamped here because MediaAssetPolicy::ownerColumn() is
        // `uploaded_by`: left null, the `media.update.own` boundary matches nobody
        // and an Author cannot edit the alt text of an image they just uploaded.
        $asset = MediaAsset::create([
            'type' => 'image',
            'alt_text' => $altText,
            'caption' => self::localeMap($data['caption'] ?? []),
            'uploaded_by' => Auth::id(),
        ]);

        // The FileUpload has already stored the file on the media disk; this moves
        // it into the library's own layout rather than copying it, so the staging
        // directory does not accumulate orphans. The disk name comes from config —
        // RULE #9 has one enforcement point.
        $asset->addMediaFromDisk($path, self::disk())
            ->toMediaCollection('file');

        $asset->syncFileMetadata();

        return (int) $asset->getKey();
    }

    /**
     * The modal's schema: the file, then the same alt text and caption fields the
     * library form uses.
     *
     * Deliberately narrower than MediaAssetForm. Video embeds, poster frames and
     * durations belong to the Media section's own workflow; offering them while
     * someone is mid-article would turn a two-field interruption into the full
     * asset form, which is the problem this is solving.
     *
     * @return array<Component>
     */
    private static function uploadSchema(): array
    {
        return [
            FileUpload::make('file')
                ->label(__('cms.field.file'))
                ->required()
                ->image()
                // RULE #9 — local disk, named in config/cms.php, never here.
                ->disk(self::disk())
                ->directory('media-library/uploads')
                ->maxSize(10 * 1024)
                ->columnSpanFull(),

            TranslatableTabs::make(fn (string $locale, bool $isSource): array => [
                Textarea::make("alt_text.{$locale}")
                    ->label(__('cms.field.alt_text'))
                    // Requirement 2.7 — mandatory in the source locale, exactly as
                    // in MediaAssetForm. The inline path must not be the lenient one.
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
        ];
    }

    /**
     * Drop locales the editor left blank.
     *
     * The page-level hooks in InteractsWithTranslatableRecord do this for a
     * resource form, but a modal action runs outside them: an untouched English
     * tab would submit `['en' => null]`, which hasAnyTranslationFor() reads as "a
     * translation exists" and the translation queue then shows work nobody did.
     *
     * @return array<string, string>
     */
    private static function localeMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $filtered = [];

        foreach ($value as $locale => $text) {
            if (is_string($locale) && filled($text)) {
                $filtered[$locale] = (string) $text;
            }
        }

        return $filtered;
    }

    /**
     * The stored path out of a FileUpload's state.
     *
     * A single FileUpload dehydrates to a uuid-keyed map rather than a string, so
     * the value is unwrapped rather than cast.
     */
    private static function uploadedPath(mixed $state): ?string
    {
        $path = is_array($state) ? Arr::first($state) : $state;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * @return array<int, string>
     */
    private static function options(int $limit): array
    {
        return MediaAsset::query()
            ->where('type', 'image')
            ->latest()
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (MediaAsset $asset): array => [
                $asset->getKey() => self::optionLabel($asset),
            ])
            ->all();
    }

    private static function labelFor(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $asset = MediaAsset::find($value);

        return $asset === null ? null : self::optionLabel($asset);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private static function labelsFor(array $values): array
    {
        if ($values === []) {
            return [];
        }

        return MediaAsset::query()
            ->whereKey($values)
            ->get()
            ->mapWithKeys(fn (MediaAsset $asset): array => [
                $asset->getKey() => self::optionLabel($asset),
            ])
            ->all();
    }

    /**
     * Alt text identifies the image to an editor far better than a filename does,
     * and it is guaranteed present in the source locale. The id is the last
     * resort, for legacy rows imported before that guarantee existed.
     */
    private static function optionLabel(MediaAsset $asset): string
    {
        return $asset->altTextFor(app()->getLocale()) ?: "#{$asset->getKey()}";
    }

    private static function disk(): string
    {
        return (string) config('cms.media.disk', 'public');
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
