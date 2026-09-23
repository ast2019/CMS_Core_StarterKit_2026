<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\MediaAsset;
use App\Services\Media\VideoMetadataExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

/**
 * Decision D-6 — fill a locally uploaded video's duration and dimensions from ffprobe.
 *
 * This listener is what makes D-6 real. `VideoMetadataExtractor` existed and was
 * correct, but nothing ever called it: `duration_seconds`, `width` and `height` for
 * video could only be typed in by hand, while the panel's help text claimed ffprobe
 * filled them automatically. Video sitemap entries were therefore missing the duration
 * Google recommends unless an editor had measured the file themselves.
 *
 * Hooked to Media Library's own event rather than a model observer, because the file is
 * attached AFTER the MediaAsset row is saved — an observer on `saved` would run while
 * there is still nothing to probe. This fires once the file is on disk, and covers every
 * route in: the panel, the Management API and seeders alike.
 *
 * Queued, for two reasons. The editor's save should not wait on a subprocess reading a
 * large file; and it keeps ffprobe a dependency of the QUEUE image only, so the web
 * image does not carry ~250 MB of ffmpeg it would never execute.
 */
class ExtractVideoMetadata implements ShouldQueue
{
    /**
     * Retrying will not help: a file that ffprobe cannot read will not become readable,
     * and the extractor already swallows and logs its own failures. A retry would only
     * re-probe a large file for nothing.
     */
    public int $tries = 1;

    public function __construct(private readonly VideoMetadataExtractor $extractor) {}

    public function handle(MediaHasBeenAddedEvent $event): void
    {
        $media = $event->media;

        // The library stores the asset itself in `file`; `video_thumbnail` is a poster
        // frame, and probing an image for a duration is meaningless.
        if ($media->collection_name !== 'file') {
            return;
        }

        $asset = $media->model;

        if (! $asset instanceof MediaAsset || ! $asset->isVideo()) {
            return;
        }

        /*
         * Never overwrite a value a human supplied. An editor who corrected a duration
         * because the container had no ffprobe must not have that correction reverted
         * the next time the file is touched.
         */
        $updates = array_filter(
            $this->extractor->probe($media->getPath()),
            fn (?int $value, string $field): bool => $value !== null && $asset->{$field} === null,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($updates === []) {
            return;
        }

        /*
         * Quietly: this is machine-derived metadata, not an editorial change. Going
         * through the normal save would emit another media/model event and write an
         * audit entry for something no user did, which makes the audit trail (RULE #8)
         * harder to read rather than more complete.
         */
        $asset->forceFill($updates)->saveQuietly();
    }
}
