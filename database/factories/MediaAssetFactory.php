<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\User;
use Database\Factories\Concerns\GeneratesPersianText;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    use GeneratesPersianText;

    protected $model = MediaAsset::class;

    public function definition(): array
    {
        return [
            // Requirement 2.7 — alt text per locale, present by default so that
            // a test needing a *missing* alt text has to ask for it explicitly.
            'alt_text' => ['fa' => $this->persianSentence(90)],
            'caption' => ['fa' => $this->persianSentence(120)],
            'type' => 'image',
            'mime_type' => 'image/webp',
            'size' => $this->faker->numberBetween(20_000, 900_000),
            'width' => 1920,
            'height' => 1080,
            'uploaded_by' => User::factory(),
        ];
    }

    public function withoutAltText(): static
    {
        return $this->state(fn (): array => ['alt_text' => null]);
    }

    /**
     * Attach a real file to the `file` collection.
     *
     * Needed by anything that reads a URL: image sitemaps, og:image, ImageObject
     * schema and the API's `variants` map all go through getFirstMedia('file'), and a
     * bare MediaAsset row has none — so without this those features look broken in
     * tests for a reason that has nothing to do with them.
     *
     * Uses addMediaFromString rather than a fixture file so the factory carries no
     * binary asset and works on a faked disk. RULE #9: it lands on the local disk
     * configured in config/cms.php, like every other upload.
     */
    public function withFile(string $collection = 'file'): static
    {
        return $this->afterCreating(function (MediaAsset $asset) use ($collection): void {
            $asset
                ->addMediaFromString($this->samplePng())
                ->usingFileName(Str::random(12).'.png')
                ->preservingOriginal()
                ->toMediaCollection($collection);
        });
    }

    /**
     * A locally hosted video WITH a thumbnail, so it forms a valid video sitemap
     * entry (Decision D-6).
     */
    public function videoWithThumbnail(): static
    {
        return $this->video()
            ->withFile()
            ->withFile('video_thumbnail');
    }

    /**
     * A small, genuinely valid PNG.
     *
     * Generated with GD rather than pasted as a base64 literal. The literal route was
     * tried first and produced a truncated file that Media Library accepted and then
     * failed to convert — "gd-png: fatal libpng error: Read Error: truncated data" —
     * which is a confusing failure to hit while testing sitemaps.
     *
     * 16x16 rather than 1x1 so the conversion pipeline (which resizes) has something
     * real to work on.
     */
    private function samplePng(): string
    {
        $image = imagecreatetruecolor(16, 16);

        if ($image === false) {
            throw new \RuntimeException('GD is required to generate media fixtures.');
        }

        imagefill($image, 0, 0, imagecolorallocate($image, 200, 200, 200));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }

    /**
     * A locally hosted video. Note it has no thumbnail, so
     * hasVideoSitemapMetadata() is false until one is attached — that is the
     * Decision D-6 constraint made visible in test data.
     */
    public function video(): static
    {
        return $this->state(fn (): array => [
            'type' => 'video',
            'mime_type' => 'video/mp4',
            'duration_seconds' => $this->faker->numberBetween(15, 600),
            'width' => 1280,
            'height' => 720,
        ]);
    }

    /**
     * An externally embedded video, which supplies its own thumbnail and needs
     * no ffprobe extraction.
     */
    public function embeddedVideo(): static
    {
        return $this->video()->state(fn (): array => [
            'external_embed_url' => 'https://example.test/embed/'.$this->faker->uuid(),
        ]);
    }
}
