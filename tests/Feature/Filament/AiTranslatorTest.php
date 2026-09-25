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

function fakeOpenRouter(string $translation = 'Translated text'): void
{
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $translation]],
            ],
        ], 200),
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

it('never sends the TipTap body to the model', function (): void {
    enableAiTranslation();
    fakeOpenRouter();

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    app(AiTranslator::class)->translate($content, 'en');

    // body is a structured document; the coder deliberately skips it. Assert no
    // request payload carried the source body text.
    Http::assertSent(function (Request $request) use ($content): bool {
        $sent = (string) json_encode($request->data());
        $body = (string) json_encode($content->getTranslation('body', 'fa', useFallbackLocale: false));

        return ! str_contains($sent, trim($body, '"')) || $body === 'null';
    });

    // And the English body remains empty — a human enters it in the editor.
    expect($content->fresh()->getTranslation('body', 'en', useFallbackLocale: false))->toBeEmpty();
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

    // A record whose translatable PROSE fields are all blank in the source. Only
    // slug and body remain, both of which the translator deliberately skips, so
    // there is nothing to send to the model.
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
