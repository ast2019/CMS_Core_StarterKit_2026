<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Page;
use Database\Factories\Concerns\GeneratesPersianText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    use GeneratesPersianText;

    protected $model = Page::class;

    public function definition(): array
    {
        return [
            'title' => ['fa' => $this->persianTitle(45)],
            'blocks' => ['fa' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => $this->persianParagraph(500)]],
                ]],
            ]],
            'status' => ContentStatus::Published,
            'publish_date' => now()->subDay(),
            'position' => $this->faker->numberBetween(0, 20),
        ];
    }

    public function notFoundPage(): static
    {
        return $this->state(fn (): array => [
            'title' => ['fa' => 'صفحه پیدا نشد'],
            'system_key' => Page::SYSTEM_NOT_FOUND,
            'status' => ContentStatus::Published,
        ]);
    }
}
