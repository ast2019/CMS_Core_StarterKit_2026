<?php

declare(strict_types=1);

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
            'ai_translation_model' => 'openai/gpt-4o-mini',
            'openrouter_api_key' => 'sk-or-secret-value',
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect((bool) Setting::get(Setting::AI_TRANSLATION_ENABLED))->toBeTrue()
        ->and(Setting::get(Setting::AI_TRANSLATION_MODEL))->toBe('openai/gpt-4o-mini')
        // Round-trips through decryption to the plaintext the admin entered.
        ->and(Setting::getSecret(Setting::OPENROUTER_API_KEY))->toBe('sk-or-secret-value');
});

it('defaults the model to openai/gpt-4o-mini on first load', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(Settings::class)
        ->assertFormSet(['ai_translation_model' => 'openai/gpt-4o-mini']);
});

it('keeps the stored key when the key field is left blank', function (): void {
    actingAs(User::factory()->admin()->create());

    Setting::putSecret(Setting::OPENROUTER_API_KEY, 'sk-or-original');

    Livewire::test(Settings::class)
        ->fillForm([
            'ai_translation_enabled' => true,
            'ai_translation_model' => 'openai/gpt-4o-mini',
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
