<?php

declare(strict_types=1);

use App\Enums\TranslationStatus;
use App\Filament\Pages\TranslationReview;
use App\Models\Content;
use App\Models\Setting;
use App\Models\User;
use App\Services\Translation\AiTranslationException;
use App\Services\Translation\AiTranslator;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Requirement 5.3 — machine translation via OpenRouter. Every test mocks the
 * outbound call with Http::fake(); none performs a live request.
 */
function enableAiTranslation(string $key = 'sk-or-test-key'): void
{
    Setting::put(Setting::AI_TRANSLATION_ENABLED, true);
    Setting::putSecret(Setting::OPENROUTER_API_KEY, $key);
}

/**
 * Fake OpenRouter for BOTH call shapes the translator now makes:
 *
 *  - Plain fields (title, excerpt, ...) go through translateText(), whose user
 *    message is a bare string; the model must answer with a bare string.
 *  - The TipTap body goes through translateBatch(), whose user message is a JSON
 *    array of prose segments; the model must answer with a JSON array of the
 *    SAME length.
 *
 * The single string helper answers every plain call with $translation. For the
 * body batch it echoes each segment through $segment (default: prefix "EN: ") so
 * a test can assert each leaf was replaced 1:1 while structure is preserved.
 *
 * @param  callable(string): string|null  $segment
 */
function fakeOpenRouter(string $translation = 'Translated text', ?callable $segment = null): void
{
    $segment ??= static fn (string $s): string => 'EN: '.$s;

    Http::fake([
        'openrouter.ai/*' => function (Request $request) use ($translation, $segment) {
            $messages = $request->data()['messages'] ?? [];
            $user = '';

            foreach ($messages as $message) {
                if (($message['role'] ?? null) === 'user') {
                    $user = (string) ($message['content'] ?? '');
                }
            }

            $decoded = json_decode($user, true);

            // A JSON array user message is the body batch: answer with a JSON
            // array of the SAME length, translating each element.
            if (is_array($decoded) && array_is_list($decoded)) {
                $translated = array_map(
                    static fn ($element): string => $segment((string) $element),
                    $decoded,
                );

                $content = (string) json_encode($translated, JSON_UNESCAPED_UNICODE);
            } else {
                $content = $translation;
            }

            return Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => $content]],
                ],
            ], 200);
        },
    ]);
}

it('translates the source locale into a target locale and lands at ai_translated', function (): void {
    enableAiTranslation();
    fakeOpenRouter('Persian first CMS');

    $content = Content::factory()->create(['title' => ['fa' => 'سی‌ام‌اس فارسی']]);

    // No English yet, so the row starts as not_translated.
    expect($content->translationStatusFor('en'))->toBe(TranslationStatus::NotTranslated);

    $result = app(AiTranslator::class)->translate($content, 'en');

    $content->refresh();

    expect($result->locale)->toBe('en')
        ->and($result->fieldCount())->toBeGreaterThan(0)
        ->and($content->getTranslation('title', 'en', useFallbackLocale: false))->toBe('Persian first CMS')
        ->and($content->translationStatusFor('en'))->toBe(TranslationStatus::AiTranslated);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'openrouter.ai')
        && $request->hasHeader('Authorization', 'Bearer sk-or-test-key'));
});

it('sends the configured model, defaulting to openai/gpt-4o-mini', function (): void {
    enableAiTranslation();
    fakeOpenRouter();

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    app(AiTranslator::class)->translate($content, 'en');

    Http::assertSent(fn (Request $request): bool => ($request->data()['model'] ?? null) === 'openai/gpt-4o-mini');
});

it('honours an admin-chosen model over the default', function (): void {
    enableAiTranslation();
    Setting::put(Setting::AI_TRANSLATION_MODEL, 'anthropic/claude-3.5-sonnet');
    fakeOpenRouter();

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    app(AiTranslator::class)->translate($content, 'en');

    Http::assertSent(fn (Request $request): bool => ($request->data()['model'] ?? null) === 'anthropic/claude-3.5-sonnet');
});

it('translates the TipTap body while preserving its structure', function (): void {
    enableAiTranslation();
    // Prefix every prose leaf so we can recognise a translated string and still
    // tell the leaves apart from one another.
    fakeOpenRouter('Machine title', static fn (string $s): string => 'EN: '.$s);

    // The factory body has: paragraph text, heading (level 2) text, another
    // paragraph text, and a callout block carrying attrs.tone + attrs.body.
    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    /** @var array<string, mixed> $sourceBody */
    $sourceBody = $content->getTranslation('body', 'fa', useFallbackLocale: false);

    app(AiTranslator::class)->translate($content, 'en');

    /** @var array<string, mixed> $enBody */
    $enBody = $content->fresh()->getTranslation('body', 'en', useFallbackLocale: false);

    // Same top-level shape and node order/types.
    expect($enBody['type'])->toBe('doc')
        ->and(array_map(fn (array $n): string => $n['type'], $enBody['content']))
        ->toBe(array_map(fn (array $n): string => $n['type'], $sourceBody['content']));

    // Structural attrs are byte-identical.
    expect($enBody['content'][1]['attrs'])->toBe(['level' => 2])
        ->and($enBody['content'][3]['attrs']['tone'])->toBe('info');

    // Every prose leaf was replaced by the faked translation of its source.
    expect($enBody['content'][0]['content'][0]['text'])
        ->toBe('EN: '.$sourceBody['content'][0]['content'][0]['text'])
        ->and($enBody['content'][1]['content'][0]['text'])
        ->toBe('EN: '.$sourceBody['content'][1]['content'][0]['text'])
        ->and($enBody['content'][2]['content'][0]['text'])
        ->toBe('EN: '.$sourceBody['content'][2]['content'][0]['text'])
        ->and($enBody['content'][3]['attrs']['body'])
        ->toBe('EN: '.$sourceBody['content'][3]['attrs']['body']);
});

it('preserves marks and structural block attrs while translating only prose', function (): void {
    enableAiTranslation();
    fakeOpenRouter('Machine title', static fn (string $s): string => 'EN: '.$s);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    // A body with a marked (bold) text leaf and a hero block whose cta_url and
    // media_asset_id are structural and must survive byte-for-byte.
    $content->setTranslation('body', 'fa', [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'متن پررنگ',
                        'marks' => [['type' => 'bold']],
                    ],
                ],
            ],
            [
                'type' => 'hero',
                'attrs' => [
                    'heading' => 'سرتیتر',
                    'lead' => 'مقدمه',
                    'cta_label' => 'اقدام کنید',
                    'cta_url' => 'https://example.test/go',
                    'media_asset_id' => 42,
                ],
            ],
        ],
    ]);
    $content->saveQuietly();

    app(AiTranslator::class)->translate($content, 'en');

    /** @var array<string, mixed> $enBody */
    $enBody = $content->fresh()->getTranslation('body', 'en', useFallbackLocale: false);

    $textNode = $enBody['content'][0]['content'][0];
    $hero = $enBody['content'][1];

    // The marked leaf: text translated, marks untouched.
    expect($textNode['text'])->toBe('EN: متن پررنگ')
        ->and($textNode['marks'])->toBe([['type' => 'bold']]);

    // Hero: prose attrs translated, structural attrs byte-identical.
    expect($hero['attrs']['heading'])->toBe('EN: سرتیتر')
        ->and($hero['attrs']['lead'])->toBe('EN: مقدمه')
        ->and($hero['attrs']['cta_label'])->toBe('EN: اقدام کنید')
        ->and($hero['attrs']['cta_url'])->toBe('https://example.test/go')
        ->and($hero['attrs']['media_asset_id'])->toBe(42);
});

it('does not re-translate a locale a human already reviewed', function (): void {
    enableAiTranslation();
    fakeOpenRouter('Should never be written');

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان فارسی']]);

    // Establish known, human-owned English content, then sign it off.
    $content->setTranslation('title', 'en', 'Hand written English title');
    $content->setTranslation('body', 'en', [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hand written English body']]],
        ],
    ]);
    $content->saveQuietly();
    $content->markTranslationReviewed('en');

    expect($content->fresh()->translationStatusFor('en'))->toBe(TranslationStatus::Reviewed);

    expect(fn () => app(AiTranslator::class)->translate($content->fresh(), 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.already_reviewed');

    // No HTTP call, and the reviewed content is untouched.
    Http::assertNothingSent();

    $fresh = $content->fresh();

    expect($fresh->getTranslation('title', 'en', useFallbackLocale: false))->toBe('Hand written English title')
        ->and($fresh->getTranslation('body', 'en', useFallbackLocale: false)['content'][0]['content'][0]['text'])
        ->toBe('Hand written English body')
        ->and($fresh->translationStatusFor('en'))->toBe(TranslationStatus::Reviewed);
});

it('replaces rather than appends when re-translating an ai_translated locale', function (): void {
    enableAiTranslation();
    fakeOpenRouter('Machine title', static fn (string $s): string => 'EN: '.$s);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    // First run: not_translated -> ai_translated.
    app(AiTranslator::class)->translate($content, 'en');

    $content = $content->fresh();
    /** @var array<string, mixed> $firstBody */
    $firstBody = $content->getTranslation('body', 'en', useFallbackLocale: false);

    expect($content->translationStatusFor('en'))->toBe(TranslationStatus::AiTranslated);

    // Second run over the now ai_translated locale is allowed.
    app(AiTranslator::class)->translate($content, 'en');

    /** @var array<string, mixed> $secondBody */
    $secondBody = $content->fresh()->getTranslation('body', 'en', useFallbackLocale: false);

    // Node count/structure identical — the body was REPLACED, not appended.
    expect(count($secondBody['content']))->toBe(count($firstBody['content']))
        ->and(array_map(fn (array $n): string => $n['type'], $secondBody['content']))
        ->toBe(array_map(fn (array $n): string => $n['type'], $firstBody['content']));

    // The body is always re-translated from the fa source (not from the prior
    // English), so the leaf carries exactly ONE "EN: " prefix — never a doubled
    // "EN: EN: ..." from appending, and the paragraph still holds a single leaf.
    $leaf = $secondBody['content'][0]['content'][0]['text'];
    expect(substr_count($leaf, 'EN: '))->toBe(1)
        ->and($secondBody['content'][0]['content'])->toHaveCount(1)
        ->and($secondBody['content'][0]['content'][0]['text'])->toBe($firstBody['content'][0]['content'][0]['text']);
});

it('short-circuits when AI translation is disabled and makes no HTTP call', function (): void {
    Setting::put(Setting::AI_TRANSLATION_ENABLED, false);
    Setting::putSecret(Setting::OPENROUTER_API_KEY, 'sk-or-test-key');
    Http::fake();

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    expect(fn () => app(AiTranslator::class)->translate($content, 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.disabled');

    Http::assertNothingSent();
});

it('fails gracefully with no HTTP call when the API key is missing', function (): void {
    Setting::put(Setting::AI_TRANSLATION_ENABLED, true);
    Http::fake();

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    expect(fn () => app(AiTranslator::class)->translate($content, 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.missing_key');

    Http::assertNothingSent();
});

it('handles an OpenRouter error response gracefully', function (): void {
    enableAiTranslation();
    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => ['message' => 'upstream boom']], 500),
    ]);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    expect(fn () => app(AiTranslator::class)->translate($content, 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.request_failed');

    // The failed attempt did not fabricate a translation.
    expect($content->fresh()->getTranslation('title', 'en', useFallbackLocale: false))->toBeEmpty();
});

it('reports an empty source rather than calling the model', function (): void {
    enableAiTranslation();
    Http::fake();

    // A record whose translatable PROSE fields are all blank in the source, AND
    // whose body is empty. Only slug remains (deliberately skipped), so there is
    // nothing — plain field or body prose — to send to the model.
    $content = Content::factory()->create([
        'title' => ['fa' => 'موقت'],
        'slug' => ['fa' => 'placeholder-slug'],
        'excerpt' => ['fa' => ''],
        'answer_paragraph' => ['fa' => ''],
        'meta_title' => ['fa' => ''],
        'meta_description' => ['fa' => ''],
        'body' => ['fa' => null],
    ]);

    // Now blank the title too, leaving only skipped fields populated.
    $content->setTranslation('title', 'fa', '');
    $content->saveQuietly();

    expect(fn () => app(AiTranslator::class)->translate($content, 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.empty_source');

    Http::assertNothingSent();
});

it('exposes the Translate with AI action on the review page and runs it', function (): void {
    enableAiTranslation();
    fakeOpenRouter('Machine English title');

    actingAs(User::factory()->admin()->create());

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان فارسی']]);
    $state = $content->translationStates()->where('locale', 'en')->firstOrFail();

    Livewire::test(TranslationReview::class)
        ->callAction(TestAction::make('translateAi')->table($state));

    expect($content->fresh()->translationStatusFor('en'))->toBe(TranslationStatus::AiTranslated)
        ->and($content->fresh()->getTranslation('title', 'en', useFallbackLocale: false))
        ->toBe('Machine English title');
});

it('hides the Translate with AI action when the feature is disabled', function (): void {
    Setting::put(Setting::AI_TRANSLATION_ENABLED, false);

    actingAs(User::factory()->admin()->create());

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);
    $state = $content->translationStates()->where('locale', 'en')->firstOrFail();

    Livewire::test(TranslationReview::class)
        ->assertActionHidden(TestAction::make('translateAi')->table($state));
});
