<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MenuItem;
use Database\Factories\Concerns\GeneratesPersianText;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    use GeneratesPersianText;

    protected $model = MenuItem::class;

    /**
     * A raw-link item by default.
     *
     * Not a bare row: MenuItem::normaliseTarget() refuses to save an item with
     * neither a link nor a target, because such an item silently never appears in
     * the API. The default state therefore has to pick one, and a raw link is the
     * state with no dependencies.
     */
    public function definition(): array
    {
        return [
            'label' => ['fa' => $this->persianTopic()],
            // The constant, not the literal: `menu_key` is now validated against the
            // locations in `cms.menus.locations`, and a factory writing a key the
            // model rejects would fail every test that touches navigation.
            'menu_key' => MenuItem::DEFAULT_MENU_KEY,
            'link' => '/fa/'.$this->faker->unique()->slug(2),
            'position' => 0,
        ];
    }

    /**
     * Point the item at a CMS record instead of a raw URL.
     */
    public function pointingAt(Model $target): static
    {
        return $this->state(fn (): array => [
            'link' => null,
            'linkable_type' => $target::class,
            'linkable_id' => $target->getKey(),
        ]);
    }

    public function childOf(MenuItem $parent): static
    {
        return $this->state(fn (): array => [
            'parent_id' => $parent->getKey(),
            'menu_key' => $parent->menu_key,
        ]);
    }

    public function inMenu(string $menuKey): static
    {
        return $this->state(fn (): array => ['menu_key' => $menuKey]);
    }
}
