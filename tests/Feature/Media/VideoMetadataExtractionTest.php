<?php

declare(strict_types=1);

use App\Models\MediaAsset;

/**
 * Decision D-6 — video duration and dimensions are derived on upload.
 *
 * These exist because the feature was, for a while, only half present: the extractor
 * was written and correct, but nothing called it, so the panel's promise that ffprobe
 * "fills both automatically" was untrue and every video sitemap entry lacked the
 * duration Google recommends. A unit test of the extractor would have stayed green
 * throughout — the defect was in the wiring, so that is what these assert.
 *
 * ffprobe is faked with a shell script. That is not a shortcut to avoid installing
 * ffmpeg: it makes the tests run identically on a developer machine, in CI and in the
 * queue container, none of which are guaranteed to have it — which is the entire premise
 * of treating ffprobe as a SOFT dependency.
 */

/**
 * A stand-in for ffprobe that answers the two invocations the extractor makes:
 * `-version` for the availability check, and the JSON probe itself.
 */
function fakeFfprobe(string $json = '{"streams":[{"width":1920,"height":1080}],"format":{"duration":"83.412000"}}'): string
{
    $path = storage_path('app/fake-ffprobe-'.Str::random(8));

    file_put_contents($path, <<<SH
        #!/bin/sh
        if [ "\$1" = "-version" ]; then
            echo "ffprobe version 0.0-fake"
            exit 0
        fi
        cat <<'JSON'
        {$json}
        JSON
        SH);

    chmod($path, 0o755);

    return $path;
}

afterEach(function (): void {
    foreach (glob(storage_path('app/fake-ffprobe-*')) ?: [] as $leftover) {
        @unlink($leftover);
    }
});

it('fills duration and dimensions from the uploaded file', function (): void {
    config()->set('cms.media.ffprobe_path', fakeFfprobe());

    $asset = MediaAsset::factory()->video()->create([
        'duration_seconds' => null,
        'width' => null,
        'height' => null,
    ]);

    // Attaching the file is what triggers extraction — the model row already existed,
    // which is precisely why a `saved` observer could not have done this job.
    $asset->addMediaFromString('not really an mp4')
        ->usingFileName('clip.mp4')
        ->toMediaCollection('file');

    expect($asset->fresh())
        // 83.412 seconds rounds to whole seconds, which is what a video sitemap wants.
        ->duration_seconds->toBe(83)
        ->width->toBe(1920)
        ->height->toBe(1080);
});

it('never overwrites metadata a person entered by hand', function (): void {
    /*
     * The case this protects: a site whose container has no ffprobe, where an editor
     * measured the video and typed the duration in. Re-touching the file must not
     * silently replace their figure with a machine's.
     */
    config()->set('cms.media.ffprobe_path', fakeFfprobe());

    $asset = MediaAsset::factory()->video()->create([
        'duration_seconds' => 42,
        'width' => null,
        'height' => null,
    ]);

    $asset->addMediaFromString('not really an mp4')
        ->usingFileName('clip.mp4')
        ->toMediaCollection('file');

    expect($asset->fresh())
        ->duration_seconds->toBe(42)
        // The fields that WERE empty are still filled.
        ->width->toBe(1920);
});

it('leaves the upload intact when ffprobe is unavailable', function (): void {
    // The designed fallback (Decision D-6): no ffprobe means the panel asks an editor
    // for the metadata. What must not happen is a failed upload.
    config()->set('cms.media.ffprobe_path', '/nonexistent/ffprobe');

    $asset = MediaAsset::factory()->video()->create([
        'duration_seconds' => null,
        'width' => null,
    ]);

    $asset->addMediaFromString('not really an mp4')
        ->usingFileName('clip.mp4')
        ->toMediaCollection('file');

    expect($asset->fresh())
        ->duration_seconds->toBeNull()
        ->width->toBeNull()
        ->and($asset->getMedia('file'))->toHaveCount(1);
});

it('does not probe a poster frame or a still image', function (): void {
    /*
     * The thumbnail lives in a different collection on the same model. Probing it would
     * write an image's dimensions over the video's, and asking for its duration is
     * meaningless.
     */
    config()->set('cms.media.ffprobe_path', fakeFfprobe());

    /*
     * Real PNG bytes, via the factory's withFile() state. Arbitrary strings named .png
     * are rejected by the conversion pipeline before the listener is ever reached, so a
     * test built on them fails for a reason unrelated to what it is checking.
     */
    $withPoster = MediaAsset::factory()
        ->video()
        ->withFile('video_thumbnail')
        ->create([
            'duration_seconds' => null,
            'width' => null,
            'height' => null,
        ]);

    expect($withPoster->fresh())->duration_seconds->toBeNull()->width->toBeNull();

    $image = MediaAsset::factory()->withFile()->create([
        'type' => 'image',
        'width' => null,
        'height' => null,
    ]);

    expect($image->fresh())->width->toBeNull();
});
