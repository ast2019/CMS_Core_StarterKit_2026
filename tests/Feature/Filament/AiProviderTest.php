<?php

declare(strict_types=1);

use App\Enums\AiProvider;
use App\Models\Content;
use App\Models\Setting;
use App\Services\Translation\AiTranslationException;
use App\Services\Translation\AiTranslator;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Requirement 5.3 — the administrator chooses WHICH service translates.
 *
 * OpenRouter, GapGPT and ChatQT all speak OpenAI's chat-completions protocol, so
 * one client serves all three and what varies is data: the endpoint, the default
 * model, the stored credential, and whether OpenRouter's attribution headers are
 * sent. These tests pin exactly those four, because they are the whole difference
 * between the providers and a silent mix-up (the right key to the wrong endpoint,
 * one provider's model id sent to another) is the failure mode the design exists to
 * prevent.
 *
 * Every test fakes the outbound call; none performs a live request.
 */

/**
 * Enable AI translation through $provider, optionally storing its key.
 */
function selectAiProvider(AiProvider $provider, ?string $key = 'sk-provider-test-key'): void
{
    Setting::put(Setting::AI_TRANSLATION_ENABLED, true);
    Setting::put(Setting::AI_TRANSLATION_PROVIDER, $provider->value);

    if ($key !== null) {
        Setting::putSecret($provider->apiKeySettingKey(), $key);
    }
}

/**
 * Answer ANY host with a correctly-shaped, 1:1 reply.
 *
 * Deliberately not scoped to one host: these tests are about WHICH endpoint the
 * translator chose, and a fake that only matches the expected host would pass by
 * refusing to answer the wrong one rather than by proving the right one was called.
 * Echoing the request's own segments keeps the translator's 1:1 contract satisfied
 * whatever batch kind or record it is given.
 */
function fakeAnyAiProvider(): void
{
    Http::fake(['*' => function (Request $request): PromiseInterface {
        $segments = [];

        foreach ($request->data()['messages'] ?? [] as $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            $decoded = json_decode((string) ($message['content'] ?? ''), true);

            if (is_array($decoded) && array_is_list($decoded)) {
                $segments = array_map('strval', $decoded);
            }
        }

        return Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => (string) json_encode(
                        ['translations' => array_map(static fn (string $s): string => 'EN: '.$s, $segments)],
                        JSON_UNESCAPED_UNICODE,
                    ),
                ],
            ]],
        ], 200);
    }]);
}

it('posts to the selected provider endpoint', function (AiProvider $provider, string $expectedHost): void {
    selectAiProvider($provider);
    fakeAnyAiProvider();

    app(AiTranslator::class)->translate(Content::factory()->create(), 'en');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), $expectedHost));

    // And nothing was sent anywhere else — a provider switch must MOVE the traffic,
    // not add a second destination.
    Http::assertNotSent(fn (Request $request): bool => ! str_contains($request->url(), $expectedHost));
})->with([
    'OpenRouter' => [AiProvider::OpenRouter, 'openrouter.ai/api/v1/chat/completions'],
    'GapGPT' => [AiProvider::GapGpt, 'api.gapgpt.app/v1/chat/completions'],
    'ChatQT' => [AiProvider::ChatQt, 'api.chatqt.com/api/v1/chat/completions'],
]);

it('authenticates with the key stored for the selected provider', function (): void {
    // All three configured, so picking the right one is a real choice rather than
    // the only key present.
    Setting::putSecret(Setting::OPENROUTER_API_KEY, 'sk-openrouter-key');
    Setting::putSecret(Setting::GAPGPT_API_KEY, 'sk-gapgpt-key');
    Setting::putSecret(Setting::CHATQT_API_KEY, 'sk-chatqt-key');

    selectAiProvider(AiProvider::GapGpt, key: null);
    fakeAnyAiProvider();

    app(AiTranslator::class)->translate(Content::factory()->create(), 'en');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk-gapgpt-key'));
    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk-openrouter-key'));
});

it('refuses when the selected provider has no key, even though another provider does', function (): void {
    /*
     * The case a shared "has a key?" check would get wrong. An admin who configured
     * OpenRouter and then switched to ChatQT HAS a key and still cannot translate;
     * reporting otherwise queues a job guaranteed to fail minutes later in a
     * notification instead of refusing instantly with something actionable.
     */
    Setting::putSecret(Setting::OPENROUTER_API_KEY, 'sk-openrouter-key');
    selectAiProvider(AiProvider::ChatQt, key: null);
    Http::fake();

    expect(AiTranslator::hasApiKey())->toBeFalse();

    expect(fn () => app(AiTranslator::class)->translate(Content::factory()->create(), 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.missing_key');

    Http::assertNothingSent();
});

it('falls back to the selected provider default model', function (AiProvider $provider, string $expectedModel): void {
    selectAiProvider($provider);
    fakeAnyAiProvider();

    expect(AiTranslator::model())->toBe($expectedModel);

    app(AiTranslator::class)->translate(Content::factory()->create(), 'en');

    Http::assertSent(fn (Request $request): bool => ($request->data()['model'] ?? null) === $expectedModel);
})->with([
    // Not portable between services, which is why the default is per provider:
    // ChatQT's own quickstart works in openai/gpt-4.1.
    'OpenRouter' => [AiProvider::OpenRouter, 'openai/gpt-4o-mini'],
    'GapGPT' => [AiProvider::GapGpt, 'openai/gpt-4o-mini'],
    'ChatQT' => [AiProvider::ChatQt, 'openai/gpt-4.1'],
]);

it('prefers an administrator-chosen model over the provider default', function (): void {
    selectAiProvider(AiProvider::ChatQt);
    Setting::put(AiProvider::ChatQt->modelSettingKey(), 'anthropic/claude-sonnet-4.6');
    fakeAnyAiProvider();

    app(AiTranslator::class)->translate(Content::factory()->create(), 'en');

    Http::assertSent(fn (Request $request): bool => ($request->data()['model'] ?? null) === 'anthropic/claude-sonnet-4.6');
});

it('keeps one provider model out of another provider request', function (): void {
    /*
     * The model is stored per provider precisely so this cannot happen. A model named
     * for OpenRouter must not travel to ChatQT, whose catalogue is its own; sending
     * it would answer 404 at the END of a paid run, reported as a generic failure.
     */
    Setting::put(AiProvider::OpenRouter->modelSettingKey(), 'meta-llama/llama-3-70b-instruct');
    selectAiProvider(AiProvider::ChatQt);
    fakeAnyAiProvider();

    expect(AiTranslator::model())->toBe('openai/gpt-4.1');

    app(AiTranslator::class)->translate(Content::factory()->create(), 'en');

    Http::assertNotSent(fn (Request $request): bool => ($request->data()['model'] ?? null) === 'meta-llama/llama-3-70b-instruct');
});

it('honours the legacy shared model setting for OpenRouter only', function (): void {
    /*
     * The upgrade path for the model. The previous Settings page pre-filled ONE
     * shared field with openai/gpt-4o-mini, so every install that ever saved
     * settings has that id stored whether or not anyone chose it. It describes
     * OpenRouter's catalogue, so OpenRouter must keep using it — byte-identical
     * behaviour after the upgrade — and the other two must ignore it rather than
     * inherit a foreign model id.
     */
    Setting::put(Setting::AI_TRANSLATION_MODEL, 'openai/gpt-4o-legacy');

    selectAiProvider(AiProvider::OpenRouter);
    expect(AiTranslator::model())->toBe('openai/gpt-4o-legacy');

    Setting::put(Setting::AI_TRANSLATION_PROVIDER, AiProvider::ChatQt->value);
    expect(AiTranslator::model())->toBe('openai/gpt-4.1');

    Setting::put(Setting::AI_TRANSLATION_PROVIDER, AiProvider::GapGpt->value);
    expect(AiTranslator::model())->toBe('openai/gpt-4o-mini');
});

it('pins a whole run to one provider even if the setting changes mid-run', function (): void {
    /*
     * The invariant the threading exists for. A run makes several sequential calls
     * over minutes; if the provider were re-read per request, an admin saving the
     * Settings page in that window would send the rest of the article to a different
     * service on the first service's key. The provider is resolved once in
     * translate(), so the switch takes effect on the NEXT run, not halfway through
     * this one.
     */
    Setting::putSecret(Setting::CHATQT_API_KEY, 'sk-chatqt-key');
    selectAiProvider(AiProvider::OpenRouter, key: 'sk-openrouter-key');

    // Force several requests, so there is a mid-run to speak of.
    config()->set('cms.ai.translation.max_segments_per_request', 1);

    Http::fake(['*' => function (Request $request): PromiseInterface {
        // An admin switching provider while the job runs.
        Setting::put(Setting::AI_TRANSLATION_PROVIDER, AiProvider::ChatQt->value);

        $segments = [];

        foreach ($request->data()['messages'] ?? [] as $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            $decoded = json_decode((string) ($message['content'] ?? ''), true);

            if (is_array($decoded) && array_is_list($decoded)) {
                $segments = array_map('strval', $decoded);
            }
        }

        return Http::response([
            'choices' => [[
                'message' => [
                    'content' => (string) json_encode(
                        ['translations' => array_map(static fn (string $s): string => 'EN: '.$s, $segments)],
                        JSON_UNESCAPED_UNICODE,
                    ),
                ],
            ]],
        ], 200);
    }]);

    app(AiTranslator::class)->translate(Content::factory()->create(), 'en');

    expect(Setting::get(Setting::AI_TRANSLATION_PROVIDER))->toBe(AiProvider::ChatQt->value);

    // Every request of the run went to OpenRouter, on OpenRouter's key.
    Http::assertNotSent(fn (Request $request): bool => ! str_contains($request->url(), 'openrouter.ai'));
    Http::assertNotSent(fn (Request $request): bool => ! $request->hasHeader('Authorization', 'Bearer sk-openrouter-key'));
});

it('sends attribution headers to OpenRouter only', function (AiProvider $provider, bool $expected): void {
    selectAiProvider($provider);
    fakeAnyAiProvider();

    app(AiTranslator::class)->translate(Content::factory()->create(), 'en');

    /*
     * HTTP-Referer/X-Title are OpenRouter's documented attribution pair. Against
     * OpenRouter they disclose nothing the body does not already carry; sent to a
     * provider that never asked for them they would hand a third party the
     * deployment's URL and the client's name for no benefit.
     */
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Title') === $expected
        && $request->hasHeader('HTTP-Referer') === $expected);
})->with([
    'OpenRouter sends them' => [AiProvider::OpenRouter, true],
    'GapGPT does not' => [AiProvider::GapGpt, false],
    'ChatQT does not' => [AiProvider::ChatQt, false],
]);

it('keeps translating through OpenRouter when no provider was ever selected', function (): void {
    /*
     * The upgrade path. An install that predates the provider choice has no
     * ai_translation_provider row and an openrouter_api_key that works; it must keep
     * working untouched, which is why AiProvider::default() is OpenRouter rather
     * than the newest addition.
     */
    Setting::put(Setting::AI_TRANSLATION_ENABLED, true);
    Setting::putSecret(Setting::OPENROUTER_API_KEY, 'sk-legacy-key');
    fakeAnyAiProvider();

    expect(Setting::get(Setting::AI_TRANSLATION_PROVIDER))->toBeNull()
        ->and(AiTranslator::provider())->toBe(AiProvider::OpenRouter)
        ->and(AiTranslator::hasApiKey())->toBeTrue();

    app(AiTranslator::class)->translate(Content::factory()->create(), 'en');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'openrouter.ai')
        && $request->hasHeader('Authorization', 'Bearer sk-legacy-key'));
});

it('falls back to the default provider when the stored value is not a known provider', function (): void {
    // A hand-edited row, or a provider dropped in a later release. Degrading to the
    // default beats a ValueError thrown inside a queued job where nobody sees it.
    Setting::put(Setting::AI_TRANSLATION_PROVIDER, 'a-provider-that-was-removed');

    expect(AiTranslator::provider())->toBe(AiProvider::default())
        ->and(AiProvider::fromValue(null))->toBe(AiProvider::OpenRouter)
        ->and(AiProvider::fromValue(42))->toBe(AiProvider::OpenRouter);
});

it('configures every provider with an https chat-completions endpoint and a model', function (AiProvider $provider): void {
    /*
     * The guard on the config map itself. The endpoints are configuration so a
     * deployment can point at a mirror, and the cost of that flexibility is that a
     * typo or a deleted entry would surface only as a generic "could not reach the
     * translation service" at the end of a paid run.
     */
    expect($provider->endpoint())->toStartWith('https://')
        ->and($provider->endpoint())->toEndWith('/chat/completions')
        ->and($provider->defaultModel())->not->toBe('')
        ->and($provider->apiKeySettingKey())->not->toBe('')
        ->and($provider->modelSettingKey())->not->toBe('')
        ->and($provider->docsUrl())->toStartWith('https://');
})->with(fn (): array => array_map(
    static fn (AiProvider $provider): array => [$provider],
    AiProvider::cases(),
));

it('takes the default provider from config rather than hardcoding it', function (): void {
    // The config entry is what the comment beside it promises: a deployment can
    // change the fallback without editing the enum.
    config()->set('cms.ai.provider', AiProvider::ChatQt->value);
    expect(AiProvider::default())->toBe(AiProvider::ChatQt)
        ->and(AiTranslator::provider())->toBe(AiProvider::ChatQt);

    // A nonsense value cannot leave the app without a provider, and resolving it
    // must not recurse through fromValue().
    config()->set('cms.ai.provider', 'not-a-provider');
    expect(AiProvider::default())->toBe(AiProvider::OpenRouter);
});

it('stores a distinct encrypted key per provider', function (): void {
    foreach (AiProvider::cases() as $provider) {
        Setting::putSecret($provider->apiKeySettingKey(), 'sk-'.$provider->value);
    }

    // Distinct setting keys, so configuring a second provider cannot destroy the
    // first one's credential — that is what makes switching a switch.
    foreach (AiProvider::cases() as $provider) {
        expect(Setting::getSecret($provider->apiKeySettingKey()))->toBe('sk-'.$provider->value);
    }

    expect(collect(AiProvider::cases())->map(fn (AiProvider $p): string => $p->apiKeySettingKey())->unique())
        ->toHaveCount(count(AiProvider::cases()));
});
