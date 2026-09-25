<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Slide;
use Database\Factories\Concerns\GeneratesPersianText;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * Point the slide at a CMS record instead of a raw URL.
     *
     * Clears `link` explicitly rather than relying on HasLinkTarget::normaliseTarget()
     * to do it on save: a factory is also used with make() and without a save, and a
     * state that only becomes correct when persisted is a trap in a test.
     */
    public function pointingAt(Model $target): static
    {
        return $this->state(fn (): array => [
            'link' => null,
            'linkable_type' => $target::class,
            'linkable_id' => $target->getKey(),
        ]);
    }

    /**
     * A slide with no destination at all — a decorative hero.
     *
     * Legitimate for a slide and impossible for a menu item, which is the one place the
     * two diverge (Slide::linkTargetIsRequired()).
     */
    public function withoutLink(): static
    {
        return $this->state(fn (): array => [
            'link' => null,
            'linkable_type' => null,
            'linkable_id' => null,
        ]);
    }
}
