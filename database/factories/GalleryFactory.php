<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Gallery;
use Database\Factories\Concerns\GeneratesPersianText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Gallery>
 */
class GalleryFactory extends Factory
{
    use GeneratesPersianText;

    protected $model = Gallery::class;

    public function definition(): array
    {
        return [
            'title' => ['fa' => $this->persianTitle(45)],
            'description' => ['fa' => $this->persianSentence(160)],
            'status' => ContentStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => ContentStatus::Published,
            'publish_date' => now()->subDays($this->faker->numberBetween(1, 200)),
        ]);
    }
}
