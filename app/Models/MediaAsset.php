<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\InteractsWithLocales;
use App\Concerns\IsAuditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Translatable\HasTranslations;

/**
 * The reusable media library (Decision D-3, tier one).
 *
 * RULE #9 — every collection and conversion is stored on the local `public`
 * disk. Requirements 2.4, 2.6, 2.7.
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
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
