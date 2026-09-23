<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tag;
use Database\Factories\Concerns\GeneratesPersianText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    use GeneratesPersianText;

    protected $model = Tag::class;

    public function definition(): array
    {
        return [
            'name' => ['fa' => $this->persianTopic().' '.$this->faker->unique()->numberBetween(1, 9999)],
        ];
    }
}
