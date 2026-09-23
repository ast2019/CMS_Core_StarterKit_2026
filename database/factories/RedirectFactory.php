<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RedirectType;
use App\Models\Redirect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Redirect>
 */
class RedirectFactory extends Factory
{
    protected $model = Redirect::class;

    public function definition(): array
    {
        return [
            'from_path' => '/fa/old/'.$this->faker->unique()->slug(2),
            'to_path' => '/fa/news/'.$this->faker->slug(2),
            'type' => RedirectType::Permanent,
        ];
    }

    public function temporary(): static
    {
        return $this->state(fn (): array => ['type' => RedirectType::Temporary]);
    }
}
