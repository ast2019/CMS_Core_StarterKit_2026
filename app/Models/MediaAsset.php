<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\InteractsWithLocales;
use App\Concerns\IsAuditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
