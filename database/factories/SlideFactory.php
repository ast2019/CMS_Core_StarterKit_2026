<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Slide;
use Database\Factories\Concerns\GeneratesPersianText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Slide>
 */
class SlideFactory extends Factory
{
    use GeneratesPersianText;

    protected $model = Slide::class;

    public function definition(): array
    {
        return [
            'title' => ['fa' => $this->persianTitle(40)],
            'subtitle' => ['fa' => $this->persianSentence(110)],
            'cta_label' => ['fa' => 'بیشتر بخوانید'],
            'link' => '/fa/news',
            'position' => $this->faker->numberBetween(0, 4),
            'is_active' => true,

            // Requirement 7.6 — explicit dimensions so the frontend can reserve
            // space and avoid layout shift.
            'image_width' => 1920,
            'image_height' => 800,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
