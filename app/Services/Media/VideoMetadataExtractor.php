<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Extracts duration and dimensions from a locally stored video using ffprobe.
 *
 * Decision D-6.
 *
 * Context for why this exists at all: blueprint §6 requires a Video Sitemap and §10
 * allows direct local video upload, but §13's toolset lists no video tooling.
 * Google's video sitemap spec requires a thumbnail (duration is only recommended),
 * and a thumbnail cannot be derived from an MP4 without something like ffmpeg.
 *
 * ffprobe is therefore treated as a SOFT dependency. When it is present this fills
 * the metadata automatically; when it is absent the panel requires a thumbnail to
 * be uploaded by hand before a locally hosted video can be published. Never
 * assuming it exists matters because the deployment target may not have it, and a
 * hard dependency would turn "upload a video" into a 500.
 */
class VideoMetadataExtractor
{
    /**
     * Seconds to allow ffprobe before giving up.
     *
     * Bounded because ffprobe on a corrupt or truncated file can block: an unbounded
     * call would hang a queue worker indefinitely on one bad upload.
     */
    private const TIMEOUT_SECONDS = 30;

    public function isAvailable(): bool
    {
        $binary = $this->binary();

        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            // A missing binary throws rather than returning a failure code, and this
            // method must answer the question without propagating that.
            return false;
        }
    }

    /**
     * Probe a video file.
     *
     * @return array{duration_seconds: int|null, width: int|null, height: int|null}
     */
    public function probe(string $absolutePath): array
    {
        $empty = ['duration_seconds' => null, 'width' => null, 'height' => null];

        if (! is_file($absolutePath)) {
            return $empty;
        }

        if (! $this->isAvailable()) {
            // Not an error condition: the panel's manual-thumbnail requirement is the
            // designed fallback, so this stays quiet rather than logging on every
            // upload.
            return $empty;
        }

        try {
            $process = new Process([
                $this->binary(),
                '-v', 'error',
                '-select_streams', 'v:0',
                '-show_entries', 'stream=width,height:format=duration',
                '-of', 'json',
                $absolutePath,
            ]);

            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->mustRun();

            /** @var array<string, mixed>|null $data */
            $data = json_decode($process->getOutput(), true);

            if (! is_array($data)) {
                return $empty;
            }

            $stream = $data['streams'][0] ?? [];
            $duration = $data['format']['duration'] ?? null;

            return [
                // ffprobe reports duration as a float string ("83.412000"); the sitemap
                // and schema both want whole seconds.
                'duration_seconds' => $duration === null ? null : (int) round((float) $duration),
                'width' => isset($stream['width']) ? (int) $stream['width'] : null,
                'height' => isset($stream['height']) ? (int) $stream['height'] : null,
            ];
        } catch (\Throwable $exception) {
            /*
             * Catches Throwable, not just ProcessFailedException: mustRun() throws that
             * one, but a timeout, a missing binary and malformed JSON all surface
             * differently, and none of them should break an upload.
             *
             * A failed probe is logged but never thrown. The upload itself succeeded,
             * and the only consequence is that an editor must supply the metadata by
             * hand — which the panel already asks for.
             */
            Log::warning('ffprobe failed to read video metadata.', [
                'path' => $absolutePath,
                'error' => $exception->getMessage(),
            ]);

            return $empty;
        }
    }

    private function binary(): string
    {
        return (string) config('cms.media.ffprobe_path', 'ffprobe');
    }
}
