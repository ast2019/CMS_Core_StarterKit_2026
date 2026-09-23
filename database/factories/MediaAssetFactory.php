<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\User;
use Database\Factories\Concerns\GeneratesPersianText;
use Illuminate\Database\Eloquent\Factories\Factory;

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
