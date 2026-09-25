<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Filament\RichContent\Blocks\CalloutBlock;
use App\Models\Content;
use App\Models\User;
use App\Support\TipTap;
use Database\Factories\Concerns\GeneratesPersianText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Content>
 */
class ContentFactory extends Factory
{
    use GeneratesPersianText;

    protected $model = Content::class;

    /**
     * Persian sample content.
     *
     * Faker's fa_IR provider produces Persian names and sentences, which matters
     * for more than realism: Latin placeholder text in an RTL panel hides
     * bidirectional layout bugs, and a starter kit whose sample data all renders
     * LTR would ship with those bugs undiscovered.
     */
    public function definition(): array
    {
        $title = $this->persianTitle();

        return [
            'title' => ['fa' => $title],
            'excerpt' => ['fa' => $this->persianSentence(180)],
            'body' => ['fa' => $this->tiptapDocument()],
            'answer_paragraph' => ['fa' => $this->persianParagraph(300)],
            'status' => ContentStatus::Draft,
            'publish_date' => null,
            'author_id' => User::factory(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => ContentStatus::Published,
            'publish_date' => $this->faker->dateTimeBetween('-1 year', 'now'),
        ]);
    }

    /**
     * Published with a future date — live status, not yet visible
     * (Requirement 3.6). Useful for asserting the Delivery API excludes it.
     */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => ContentStatus::Published,
            'publish_date' => $this->faker->dateTimeBetween('+1 day', '+2 months'),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'status' => ContentStatus::Archived,
            'publish_date' => $this->faker->dateTimeBetween('-2 years', '-1 year'),
        ]);
    }

    /**
     * Adds English and Arabic text, for exercising the translation lifecycle.
     */
    public function multilingual(): static
    {
        return $this->state(function (array $attributes): array {
            return [
                'title' => [
                    'fa' => $attributes['title']['fa'],
                    'en' => 'Sample article title',
                    'ar' => 'عنوان مقال تجريبي',
                ],
                'excerpt' => [
                    'fa' => $attributes['excerpt']['fa'],
                    'en' => 'A short English summary of the article.',
                    'ar' => 'ملخص قصير للمقال.',
                ],
            ];
        });
    }

    /**
     * RULE #6 — the editor stores structured TipTap JSON, not HTML.
     *
     * @return array<string, mixed>
     */
    protected function tiptapDocument(): array
    {
        return [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => $this->persianParagraph(450)],
                    ],
                ],
                [
                    'type' => 'heading',
                    'attrs' => ['level' => 2],
                    'content' => [
                        ['type' => 'text', 'text' => $this->persianTitle(50).'؟'],
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => $this->persianParagraph(350)],
                    ],
                ],
                $this->calloutBlock($this->persianSentence(160)),
            ],
        ];
    }

    /**
     * A callout custom block in the shape Filament ACTUALLY saves (RULE #6).
     *
     * This matters more than it looks. Every block is stored under the single node
     * type `customBlock`, with the block id, its field values and its cached
     * preview one level down inside `attrs`:
     *
     *     {"type":"customBlock","attrs":{"id":"callout","config":{...},
     *      "label":"...","preview":"<base64 html>",...}}
     *
     * This factory previously emitted `{"type":"callout","attrs":{...fields...}}`,
     * a shape no editor can produce. Because the fixture and the production code
     * shared the same wrong assumption, the tests agreed with each other while
     * real block prose was invisible to both search indexing and the AI
     * translator. The fixture now mirrors CustomBlockAction, so a divergence shows
     * up as a failing test instead of as missing Persian text in production.
     *
     * @return array<string, mixed>
     */
    protected function calloutBlock(string $body, string $tone = 'info'): array
    {
        $config = ['tone' => $tone, 'title' => '', 'body' => $body];

        return [
            'type' => TipTap::CUSTOM_BLOCK_NODE_TYPE,
            'attrs' => [
                'config' => $config,
                'id' => CalloutBlock::getId(),
                'label' => CalloutBlock::getPreviewLabel($config),
                'preview' => base64_encode((string) CalloutBlock::toPreviewHtml($config)),
                'shouldApplyProseStylingToPreview' => CalloutBlock::shouldApplyProseStylingToPreview($config),
            ],
        ];
    }
}
