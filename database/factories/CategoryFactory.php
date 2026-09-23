<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use Database\Factories\Concerns\GeneratesPersianText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    use GeneratesPersianText;

    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'name' => ['fa' => $this->persianTopic(allowCompound: true)],
            'position' => $this->faker->numberBetween(0, 20),
        ];
    }

    public function childOf(Category $parent): static
    {
        return $this->state(fn (): array => ['parent_id' => $parent->getKey()]);
    }
}
