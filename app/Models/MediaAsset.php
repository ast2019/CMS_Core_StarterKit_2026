<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\InteractsWithLocales;
use App\Concerns\IsAuditable;
use App\Services\Content\UsageInspector;
use App\Support\Dates\LocalizedDate;
use App\Support\OrganisationProfile;
use App\Support\Plural;
use Closure;
use finfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Translatable\HasTranslations;
use SplFileInfo;

/**
 * The reusable media library (Decision D-3, tier one).
 *
 * RULE #9 — every collection and conversion is stored on the local `public`
 * disk. Requirements 2.4, 2.6, 2.7.
 *
 * Item 11 — soft-deleted, and this is the model where the trash earns its keep most and is
 * most dangerous.
 *
 * Most, because an asset carries per-locale alt text and captions that somebody wrote, is
 * reused across many records, and used to be destroyed along with its file by one click on a
 * list row. Media Library's own `deleting` hook already distinguishes the two: it preserves
 * the stored files on a soft delete and removes them on a force delete, so the trash is
 * genuinely reversible rather than a row pointing at a file that is gone.
 *
 * Most dangerous, because a relation to a trashed asset resolves to NULL while the
 * `media_attachments` row stays exactly where it was. A published article whose featured
 * image had been trashed would be served with `featured_image: null` in a 200 response, and
 * RULE #7 was enforced only in the panel's form (MediaAssetPicker) — no model guard, no
 * policy check, no database constraint. Nobody would notice until a reader did. Hence
 * guardFeaturedImageUse() below.
 *
 * @property array<string, string>|string|null $alt_text
 */
class MediaAsset extends Model implements HasMedia
{
    use HasFactory;
    use HasTranslations;
    use InteractsWithLocales;
    use InteractsWithMedia;
    use IsAuditable;
    use SoftDeletes;

    /**
     * Per-locale descriptions. Describing an image once, on the asset, keeps it
     * correct everywhere the asset is reused — which is the reason the library
     * exists rather than uploading per article.
     *
     * @var list<string>
     */
    public array $translatable = ['alt_text', 'caption'];

    protected $fillable = [
        'alt_text',
        'caption',
        'type',
        'mime_type',
        'size',
        'width',
        'height',
        'duration_seconds',
        'external_embed_url',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        /*
         * ON `forceDeleting` AS WELL AS `deleting`, AND THE ORDER IS THE WHOLE POINT.
         *
         * Media Library registers its own `deleting` listener in bootInteractsWithMedia(), and
         * Eloquent runs bootTraits() BEFORE booted() — so on the force path its listener fired
         * first, deleted the media rows and the files from disk, and only then did this guard throw
         * and abort the delete. The result was the worst of both: a surviving asset row pointing at
         * a file that no longer existed, which is neither refusing nor deleting.
         *
         * `forceDeleting` fires before SoftDeletes::forceDelete() calls delete() at all, so the
         * guard now gets there first regardless of trait boot order — which is a fact about
         * Eloquent's API rather than about which trait happens to be listed first in this class.
         */
        static::forceDeleting(function (self $asset): void {
            $asset->guardFeaturedImageUse();
        });

        static::deleting(function (self $asset): void {
            $asset->guardFeaturedImageUse();
        });

        /*
         * Item 12 — the type decides what the file may be, so changing the type of an asset that
         * already holds a file must not turn a PDF into an "image". The form refuses it with a field
         * error first; this is the same rule for every other writer (a seeder, a future API).
         *
         * Only on a type CHANGE. Rows written before the rule existed may disagree already, and an
         * editor fixing their alt text must not be blocked by that.
         */
        static::updating(function (self $asset): void {
            if (! $asset->isDirty('type')) {
                return;
            }

            $mime = $asset->storedMimeType();

            if ($mime !== null && ! self::typeAccepts((string) $asset->type, $mime)) {
                throw ValidationException::withMessages([
                    'type' => __('cms.media.validation.type_mismatch', [
                        'type' => self::typeOptions()[(string) $asset->type] ?? (string) $asset->type,
                        'mime' => $mime,
                    ]),
                ]);
            }
        });
    }

    /**
     * The MIME types an asset of this type may hold (`cms.media.mime_types`). Empty for an unknown
     * type, so an upload against it is refused rather than accepted as anything.
     *
     * @return list<string>
     */
    public static function mimeTypesFor(?string $type): array
    {
        $types = (array) config('cms.media.mime_types', []);
        $list = $types[(string) $type] ?? [];

        return is_array($list) ? array_values(array_map(strval(...), $list)) : [];
    }

    /**
     * The file-name extensions an asset of this type may be stored under (`cms.media.extensions`).
     *
     * @return list<string>
     */
    public static function extensionsFor(?string $type): array
    {
        $types = (array) config('cms.media.extensions', []);
        $list = $types[(string) $type] ?? [];

        return is_array($list) ? array_values(array_map(fn (mixed $ext): string => strtolower((string) $ext), $list)) : [];
    }

    public static function typeAccepts(string $type, string $mime): bool
    {
        return in_array(strtolower($mime), self::mimeTypesFor($type), true);
    }

    /**
     * A per-file validation rule: the uploaded file's CONTENT must be a type this asset type may
     * hold, and an image must actually decode.
     *
     * In addition to Filament's `mimetypes` rule, not instead of it, because that one asks the upload
     * object for its type — and Livewire's answer depends on Livewire (in a test it is the type the
     * client claimed). This reads the bytes on disk with PHP's own finfo, so the guarantee does not
     * rest on another library's internals. getimagesize() then refuses a file that claims to be a
     * PNG but that the image conversions could never read.
     *
     * And the NAME: the file is stored under the extension the client chose and served by it, so a
     * `.html` holding "plain text" (libmagic looks for markup only in the first 4 KB) would be
     * served as a page on the panel's origin. `cms.media.extensions` lists what each type may use.
     */
    public static function fileContentRule(?string $type): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($type): void {
            $path = $value instanceof SplFileInfo ? $value->getRealPath() : false;
            $mime = is_string($path) && is_file($path) ? self::detectMimeType($path) : null;
            $extension = $value instanceof UploadedFile
                ? strtolower($value->getClientOriginalExtension())
                : null;

            if ($extension === null || ! in_array($extension, self::extensionsFor($type), true)) {
                $fail(__('cms.media.validation.file_extension', [
                    'type' => self::typeOptions()[(string) $type] ?? (string) $type,
                    'extensions' => implode(', ', self::extensionsFor($type)),
                ]));

                return;
            }

            $accepted = $mime !== null
                && self::typeAccepts((string) $type, $mime)
                && ($type !== 'image' || @getimagesize((string) $path) !== false);

            if (! $accepted) {
                $fail(__('cms.media.validation.file_type', [
                    'type' => self::typeOptions()[(string) $type] ?? (string) $type,
                    'mime' => $mime ?? '?',
                ]));
            }
        };
    }

    /**
     * The MIME type of a file on disk, from its content.
     */
    public static function detectMimeType(string $path): ?string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    /**
     * The MIME type of the file this asset holds: the media row's, which Media Library read from
     * the file, falling back to the copy on the asset row.
     */
    public function storedMimeType(): ?string
    {
        $mime = $this->getFirstMedia('file')->mime_type ?? $this->mime_type;

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Refuse to delete an asset that is some record's featured image (RULE #7).
     *
     * ON THE MODEL, not only in the panel. RULE #7 was previously enforced by
     * MediaAssetPicker::featured() — a form component — so it held for an editor filling in
     * a form and for nobody else: not for a bulk action, not for the Management API, not for
     * a seeder, and not for the delete action on the media list. Soft deletes make that gap
     * matter, because the resulting state is a published article serving
     * `featured_image: null` with a 200 status rather than anything that looks like an error.
     *
     * Counted from the pivot table directly rather than through the morph relations: that
     * would mean one query per attachable type, and the pivot is the thing that actually
     * holds the reference. Rows belonging to SOFT-DELETED records count too — a trashed
     * article still holds its attachment, and restoring it must not find its image gone.
     *
     * A ValidationException rather than a bespoke exception, so the panel renders it as a
     * form error and the API answers 422 with a message that names what to fix. It fires on
     * a force delete as well, which is deliberate: permanence makes the problem worse, not
     * exempt.
     *
     * Inline media inside a body is NOT protected. That is the intended asymmetry — a
     * missing inline image leaves a gap in a paragraph, while a missing featured image breaks
     * the card, the Open Graph tag, the JSON-LD and the image sitemap entry at once.
     */
    protected function guardFeaturedImageUse(): void
    {
        /*
         * Delegated to UsageInspector rather than counted here, so the model and the panel cannot
         * disagree about what "in use" means. This method used to carry its own copy of the pivot
         * query — two definitions of one rule, with only one of them consulted by the confirmation
         * dialog the editor actually reads.
         */
        $blocked = app(UsageInspector::class)->blockedReason($this);

        if ($blocked === null) {
            return;
        }

        throw ValidationException::withMessages([
            'media' => Plural::trans($blocked['key'], $blocked['parameters']),
        ]);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')
            ->singleFile()
            ->useDisk((string) config('cms.media.disk', 'public'));

        /*
         * Decision D-6: a manually supplied poster frame. Required before a
         * locally hosted video can be published when ffprobe is unavailable,
         * because a Video Sitemap entry without a thumbnail is invalid.
         */
        $this->addMediaCollection('video_thumbnail')
            ->singleFile()
            ->useDisk((string) config('cms.media.disk', 'public'));
    }

    /**
     * Image derivatives (blueprint §10, Requirement 2.6).
     *
     * Two conversions per size: one keeping the original format for
     * compatibility, and a WebP sibling. WebP is typically 25-35% smaller at
     * equal quality, which matters most for the homepage slideshow's preloaded
     * hero image (Requirement 7.6).
     *
     * No ->storeOn() call here: Conversion has no such method in Media Library
     * v11. The destination comes from `conversions_disk_name` in
     * config/media-library.php, which this project pins to the local media disk —
     * so RULE #9 is enforced in one place rather than repeated per conversion.
     *
     * Conversions stay queued (the package default), per blueprint §13 and the
     * design: generating six derivatives of a 4000px upload inline would block
     * the editor's save request for seconds.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        /** @var array<string, int> $sizes */
        $sizes = (array) config('cms.media.conversions', []);

        foreach ($sizes as $name => $width) {
            // Fit::Max never upscales, so a small logo is not blown up to 1920px
            // and stored at several times its useful size. The height bound is
            // deliberately generous to allow tall infographics through unclipped.
            $this->addMediaConversion($name)
                ->keepOriginalImageFormat()
                ->fit(Fit::Max, $width, $width * 4);

            $this->addMediaConversion("{$name}_webp")
                ->fit(Fit::Max, $width, $width * 4)
                ->format('webp');
        }
    }

    /**
     * The asset types, value => label in the active locale (item 55).
     *
     * One list for the form, the table badge and the filter. The three used to spell the types out
     * separately — two of them as the raw English keys — so the panel showed `image` in a Persian
     * interface, and a fourth type added to one place would have been missing from the other two.
     *
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            'image' => __('cms.media.type.image'),
            'video' => __('cms.media.type.video'),
            'document' => __('cms.media.type.document'),
        ];
    }

    /**
     * The stored size in the reader's own units and digits — «۳٫۲ مگابایت», not "3277".
     *
     * Null when the size was never recorded (the backfill command exists for exactly those rows), so
     * the table shows its placeholder rather than a confident "0".
     */
    public function humanSize(): ?string
    {
        if ($this->size === null) {
            return null;
        }

        $kb = $this->size / 1024;

        return $kb >= 1024
            ? __('cms.media.size_mb', ['size' => LocalizedDate::number(round($kb / 1024, 1))])
            : __('cms.media.size_kb', ['size' => LocalizedDate::number((int) round($kb))]);
    }

    public function isImage(): bool
    {
        return $this->type === 'image';
    }

    public function isVideo(): bool
    {
        return $this->type === 'video';
    }

    /**
     * Whether this asset carries the metadata a Video Sitemap entry needs.
     *
     * Google requires a thumbnail; duration is only recommended, so its absence
     * does not disqualify the entry (Decision D-6).
     */
    public function hasVideoSitemapMetadata(): bool
    {
        if (! $this->isVideo()) {
            return false;
        }

        return filled($this->external_embed_url)
            || $this->hasMedia('video_thumbnail');
    }

    public function altTextFor(string $locale): string
    {
        return (string) ($this->getTranslation('alt_text', $locale, useFallbackLocale: true) ?? '');
    }

    /**
     * Copy the stored file's own facts onto the asset row.
     *
     * `mime_type`, `size`, `width` and `height` are columns on the asset, but
     * nothing in the panel ever filled them — so every image uploaded through the
     * admin reached the Delivery API with null dimensions, and a frontend that
     * follows Requirement 7.6 ("reserve space before the image loads") had nothing
     * to reserve it with. SchemaBuilder's ImageObject drops width/height for the
     * same reason.
     */
    public function syncFileMetadata(): void
    {
        $metadata = $this->fileMetadata();

        if ($metadata === []) {
            return;
        }

        $this->forceFill($metadata);

        if ($this->isDirty()) {
            $this->save();
        }
    }

    /**
     * The facts the stored file can tell us about itself, as an attribute array.
     *
     * Separated from syncFileMetadata() because the WRITE differs between the two
     * callers while the DERIVATION must not. The upload path saves normally (an
     * editor is attaching a file, and the save is part of their action);
     * cms:backfill-media-metadata writes quietly and without touching timestamps,
     * because it is retroactively correcting our record of files that have not
     * changed. Two copies of "how do we read a file's dimensions" is how the
     * backfill ends up disagreeing with the uploader about what a correct row
     * looks like.
     *
     * Only keys that could actually be DETERMINED are returned. An asset whose file
     * has gone missing from disk still has a mime type and a byte size recorded on
     * its media row — those are real facts, worth writing — but no dimensions can be
     * read, and inventing a zero would be worse than leaving the column null, which
     * SchemaBuilder and SocialTagBuilder already handle.
     *
     * Read from the ORIGINAL file, never from a conversion: conversions are queued
     * (config/media-library.php), so at the moment an upload finishes the
     * derivatives do not exist yet and asking one for its size would report null
     * for every fresh upload.
     *
     * @return array<string, int|string|null>
     */
    public function fileMetadata(): array
    {
        $media = $this->getFirstMedia('file');

        if ($media === null) {
            return [];
        }

        $metadata = [
            'mime_type' => $media->mime_type,
            'size' => $media->size,
        ];

        // Dimensions are meaningful for images only, and getimagesize() on a video
        // or a PDF returns false rather than throwing, so the guard is about intent
        // rather than safety.
        if ($this->isImage() && ! $this->fileIsMissingFromDisk()) {
            $dimensions = @getimagesize($media->getPath());

            if (is_array($dimensions)) {
                $metadata['width'] = $dimensions[0];
                $metadata['height'] = $dimensions[1];
            }
        }

        return $metadata;
    }

    /**
     * Whether this asset has a media row whose file is not on disk.
     *
     * False for an asset with no media row at all — that is a different condition
     * (nothing was ever attached) and the two must not be conflated, because one is
     * an incomplete upload and the other is data loss.
     *
     * The distinction earns its keep in the backfill command, which has to keep
     * going past a missing file rather than aborting the run, and has to be able to
     * tell an operator which of the two it found.
     */
    public function fileIsMissingFromDisk(): bool
    {
        $media = $this->getFirstMedia('file');

        if ($media === null) {
            return false;
        }

        // Asked of the DISK rather than of the local filesystem, so the answer is
        // still correct if a deployment ever points the media disk somewhere other
        // than the default root. RULE #9 keeps it local either way.
        return ! Storage::disk($media->disk)->exists($media->getPathRelativeToRoot());
    }

    /**
     * Assets whose stored metadata is incomplete.
     *
     * The backfill's candidate set, expressed as a scope so the command and its
     * test ask the same question. `mime_type` and `size` apply to every asset;
     * dimensions are only expected of an image, so a video with null width is not a
     * candidate and a re-run does not keep picking it up for ever.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeMissingFileMetadata(Builder $query): void
    {
        $query->where(function (Builder $inner): void {
            $inner->whereNull('mime_type')
                ->orWhereNull('size')
                ->orWhere(function (Builder $image): void {
                    $image->where('type', 'image')
                        ->where(fn (Builder $q) => $q->whereNull('width')->orWhereNull('height'));
                });
        });
    }

    /**
     * A URL safe to render in the panel immediately after an upload.
     *
     * Conversions are generated on the queue, so `getUrl('thumb')` resolves to a
     * path that does not exist yet for a just-uploaded file — Media Library returns
     * the URL regardless, and the panel would render a broken image. Hence the
     * explicit hasGeneratedConversion() check with the original as the fallback.
     */
    public function previewUrl(string $conversion = 'thumb'): ?string
    {
        $media = $this->getFirstMedia('file');

        if ($media === null) {
            return null;
        }

        if ($media->hasGeneratedConversion($conversion)) {
            return $media->getUrl($conversion);
        }

        return $media->getUrl();
    }

    /**
     * Assets attached to nothing the CMS tracks — item 12's "unused media" filter.
     *
     * Tracked means a `media_attachments` row (featured image, gallery item, slide, social image;
     * owners in the trash included, as UsageInspector counts them) or being the site logo, which is
     * an id inside a Setting rather than a row.
     *
     * NOT "safe to delete": images placed inside body text (hero blocks, uploaded inline images) are
     * stored in the rich-text document and are invisible here. The filter's label says so.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeUnattached(Builder $query): void
    {
        $query->whereNotExists(fn (QueryBuilder $attachments) => $attachments
            ->selectRaw('1')
            ->from('media_attachments')
            ->whereColumn('media_attachments.media_asset_id', $this->getQualifiedKeyName()));

        $logo = OrganisationProfile::logoId();

        if ($logo !== null) {
            $query->whereKeyNot($logo);
        }
    }

    /**
     * Assets missing alt text in the source locale.
     *
     * Surfaced in the panel because Requirement 2.7 blocks publishing without
     * it, and an editor should find out before hitting publish.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeMissingAltText(Builder $query): void
    {
        $locale = (string) config('cms.locales.source', 'fa');

        $query->where(function (Builder $inner) use ($locale): void {
            $inner->whereNull('alt_text')
                ->orWhereJsonLength('alt_text', 0)
                ->orWhere(fn (Builder $q) => $q->whereJsonContainsLocale('alt_text', $locale, null));
        });
    }
}
