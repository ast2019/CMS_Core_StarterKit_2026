<?php

declare(strict_types=1);

use App\Enums\AiProvider;
use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;

/**
 * Requirement 5.3 and RULE #8 — the Settings page persists AI-translation
 * configuration to the database, and the API key never reaches the audit trail.
 */
it('is accessible to an administrator', function (): void {
    actingAs(User::factory()->admin()->create());

    expect(Settings::canAccess())->toBeTrue();

    Livewire::test(Settings::class)->assertOk();
});

it('is forbidden to a non-admin', function (): void {
    // settings.manage is granted to Admin alone in the D-10 matrix.
    actingAs(User::factory()->editor()->create());

    expect(Settings::canAccess())->toBeFalse();
});

it('persists the AI translation settings to the Setting model', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(Settings::class)
        ->fillForm([
            'ai_translation_enabled' => true,
            'ai_translation_provider' => AiProvider::OpenRouter->value,
            AiProvider::OpenRouter->modelSettingKey() => 'openai/gpt-4o-mini',
            'openrouter_api_key' => 'sk-or-secret-value',
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect((bool) Setting::get(Setting::AI_TRANSLATION_ENABLED))->toBeTrue()
        ->and(Setting::get(Setting::AI_TRANSLATION_PROVIDER))->toBe(AiProvider::OpenRouter->value)
        ->and(Setting::get(AiProvider::OpenRouter->modelSettingKey()))->toBe('openai/gpt-4o-mini')
        // Round-trips through decryption to the plaintext the admin entered.
        ->and(Setting::getSecret(Setting::OPENROUTER_API_KEY))->toBe('sk-or-secret-value');
});

it('selects OpenRouter and leaves the model blank on first load', function (): void {
    actingAs(User::factory()->admin()->create());

    /*
     * Blank rather than prefilled with a concrete model id, which is what this
     * asserted before the provider choice existed. A prefilled id is pinned to ONE
     * provider's catalogue, so it silently becomes wrong the moment an admin
     * switches service; blank means "use this provider's default", which follows the
     * provider automatically. The default is surfaced as the field's placeholder
     * instead, so the admin still sees which model will be used.
     */
    Livewire::test(Settings::class)
        ->assertFormSet([
            'ai_translation_provider' => AiProvider::OpenRouter->value,
            AiProvider::OpenRouter->modelSettingKey() => '',
        ]);
});

it('shows a legacy shared model as OpenRouter model on load', function (): void {
    actingAs(User::factory()->admin()->create());

    // What an install upgraded from the single-provider version looks like.
    Setting::put(Setting::AI_TRANSLATION_MODEL, 'openai/gpt-4o-legacy');

    Livewire::test(Settings::class)
        ->assertFormSet([
            // Shown against OpenRouter, because that is the catalogue it came from…
            AiProvider::OpenRouter->modelSettingKey() => 'openai/gpt-4o-legacy',
            // …and not inherited by the others.
            AiProvider::ChatQt->modelSettingKey() => '',
        ]);
});

it('stores the key against the selected provider without disturbing the others', function (): void {
    actingAs(User::factory()->admin()->create());

    Setting::putSecret(Setting::OPENROUTER_API_KEY, 'sk-or-existing');

    Livewire::test(Settings::class)
        ->fillForm([
            'ai_translation_enabled' => true,
            'ai_translation_provider' => AiProvider::ChatQt->value,
        ])
        // The key field is only rendered for the selected provider, so switching
        // reveals ChatQT's and hides OpenRouter's.
        ->assertFormFieldExists('chatqt_api_key')
        ->fillForm(['chatqt_api_key' => 'sk-chatqt-secret'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get(Setting::AI_TRANSLATION_PROVIDER))->toBe(AiProvider::ChatQt->value)
        ->and(Setting::getSecret(Setting::CHATQT_API_KEY))->toBe('sk-chatqt-secret')
        // Configuring a second provider must not cost you the first one's key.
        ->and(Setting::getSecret(Setting::OPENROUTER_API_KEY))->toBe('sk-or-existing');
});

it('keeps a key typed before the admin switched provider', function (): void {
    actingAs(User::factory()->admin()->create());

    /*
     * Filament prunes hidden fields from the submitted state by default, so typing
     * an OpenRouter key and THEN changing the radio used to discard the credential
     * while still reporting "Settings saved" — the admin would only find out when
     * the translate action claimed no key was configured. The fields declare
     * dehydratedWhenHidden() to keep it.
     */
    Livewire::test(Settings::class)
        ->fillForm([
            'ai_translation_enabled' => true,
            'ai_translation_provider' => AiProvider::OpenRouter->value,
            'openrouter_api_key' => 'sk-typed-before-the-switch',
        ])
        // Changing their mind after typing the key.
        ->fillForm(['ai_translation_provider' => AiProvider::GapGpt->value])
        ->fillForm(['gapgpt_api_key' => 'sk-gapgpt-secret'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get(Setting::AI_TRANSLATION_PROVIDER))->toBe(AiProvider::GapGpt->value)
        ->and(Setting::getSecret(Setting::GAPGPT_API_KEY))->toBe('sk-gapgpt-secret')
        ->and(Setting::getSecret(Setting::OPENROUTER_API_KEY))->toBe('sk-typed-before-the-switch');
});

it('removes a stored key on request', function (): void {
    actingAs(User::factory()->admin()->create());

    // Blank means "unchanged" everywhere else, so revoking a leaked key needs an
    // explicit action rather than a database visit.
    Setting::putSecret(Setting::OPENROUTER_API_KEY, 'sk-or-compromised');

    Livewire::test(Settings::class)
        ->callFormComponentAction(
            Setting::OPENROUTER_API_KEY,
            'clear_'.Setting::OPENROUTER_API_KEY,
        );

    expect(Setting::getSecret(Setting::OPENROUTER_API_KEY))->toBeNull();
});

it('keeps the stored key when the key field is left blank', function (): void {
    actingAs(User::factory()->admin()->create());

    Setting::putSecret(Setting::OPENROUTER_API_KEY, 'sk-or-original');

    Livewire::test(Settings::class)
        ->fillForm([
            'ai_translation_enabled' => true,
            'ai_translation_provider' => AiProvider::OpenRouter->value,
            AiProvider::OpenRouter->modelSettingKey() => 'openai/gpt-4o-mini',
            'openrouter_api_key' => null,
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::getSecret(Setting::OPENROUTER_API_KEY))->toBe('sk-or-original');
});

it('never writes the raw API key into the audit trail', function (): void {
    actingAs(User::factory()->admin()->create());

    $rawKey = 'sk-or-super-secret-1234567890';

    Setting::putSecret(Setting::OPENROUTER_API_KEY, $rawKey);

    // The activity_log properties capture the `value` column. Because the key is
    // encrypted at rest, what was logged is ciphertext, so the plaintext key must
    // not appear anywhere in the trail.
    $properties = Activity::query()->pluck('properties')->toJson();

    expect(str_contains($properties, $rawKey))->toBeFalse(
        'The raw OpenRouter API key must never appear in the audit trail.',
    );

    // And there IS an audit row for the write — secrecy is achieved by encryption,
    // not by silently skipping the log (which RULE #8 forbids).
    expect(Activity::query()->where('subject_type', Setting::class)->exists())->toBeTrue();
});
