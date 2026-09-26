<?php

declare(strict_types=1);

use App\Enums\AiProvider;
use App\Filament\Pages\Settings;
use App\Models\ContactSetting;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteIdentity;
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
            // site_name is required on the General tab, so every save supplies it.
            'site_name' => ['fa' => 'سایت آزمایشی'],
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
            // site_name is required on the General tab, so every save supplies it.
            'site_name' => ['fa' => 'سایت آزمایشی'],
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
            // site_name is required on the General tab, so every save supplies it.
            'site_name' => ['fa' => 'سایت آزمایشی'],
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
            // site_name is required on the General tab, so every save supplies it.
            'site_name' => ['fa' => 'سایت آزمایشی'],
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

/*
|--------------------------------------------------------------------------
| Site settings that the FRONTEND consumes
|--------------------------------------------------------------------------
|
| These values change nothing an admin can see in this panel: they are stored here
| and published over the Delivery API for the separate frontend deployment to render.
| Before this page existed they were seeded with placeholders and editable only
| through the database, so a client site launched calling itself "سایت نمونه".
|
*/

it('persists the site identity and publishes it to the Delivery API', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(Settings::class)
        ->fillForm([
            'site_name' => ['fa' => 'خبرگزاری نمونه', 'en' => 'Sample News'],
            // Repeater state is rows, even for simple(); the STORED setting is flat.
            'social_links' => [['url' => 'https://example.test/fa'], ['url' => 'https://example.test/en']],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get(Setting::SITE_NAME))
        ->toBe(['fa' => 'خبرگزاری نمونه', 'en' => 'Sample News']);

    // The point of the page: what an editor typed is what the frontend receives.
    $this->getJson('/api/v1/settings?locale=fa')
        ->assertOk()
        ->assertJsonPath('data.site_name', 'خبرگزاری نمونه')
        ->assertJsonPath('data.social_links', ['https://example.test/fa', 'https://example.test/en']);

    $this->getJson('/api/v1/settings?locale=en')
        ->assertOk()
        ->assertJsonPath('data.site_name', 'Sample News');
});

it('keeps social_links a list when only one profile is configured', function (): void {
    actingAs(User::factory()->admin()->create());

    /*
     * The `settings.value` array cast unwraps a single-element list, so reading this
     * straight from Setting returned a bare STRING for exactly one profile and an
     * array for zero or two — a shape the frontend could not consume without
     * special-casing a number. SiteIdentity owns that rule now.
     */
    Livewire::test(Settings::class)
        ->fillForm([
            'site_name' => ['fa' => 'سایت آزمایشی'],
            'social_links' => [['url' => 'https://example.test/only']],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $this->getJson('/api/v1/settings?locale=fa')
        ->assertOk()
        ->assertJsonPath('data.social_links', ['https://example.test/only']);
});

it('persists analytics and verification tokens without ever loading them in the panel', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(Settings::class)
        ->fillForm([
            'site_name' => ['fa' => 'سایت آزمایشی'],
            'ga_measurement_id' => 'G-TEST12345',
            'gtm_container_id' => 'GTM-TEST99',
            'gsc_verification' => 'gsc-token',
            'bing_verification' => 'bing-token',
        ])
        ->call('save')
        ->assertHasNoErrors();

    $this->getJson('/api/v1/settings?locale=fa')
        ->assertOk()
        ->assertJsonPath('data.analytics.ga_measurement_id', 'G-TEST12345')
        ->assertJsonPath('data.analytics.gtm_container_id', 'GTM-TEST99')
        ->assertJsonPath('data.verification.google', 'gsc-token')
        ->assertJsonPath('data.verification.bing', 'bing-token');

    /*
     * RULE #4 — the backoffice makes no external requests. These ids exist so the
     * FRONTEND can render a tag; the panel storing one must never start loading it.
     */
    $panel = $this->get('/'.config('cms.brand.panel_path'));
    $body = $panel->getContent();

    expect($body)->not->toContain('G-TEST12345')
        ->and($body)->not->toContain('googletagmanager.com');
});

it('clears an emptied token to null rather than an empty string', function (): void {
    actingAs(User::factory()->admin()->create());

    Setting::put(Setting::GA_MEASUREMENT_ID, 'G-OLD');

    Livewire::test(Settings::class)
        ->fillForm([
            'site_name' => ['fa' => 'سایت آزمایشی'],
            'ga_measurement_id' => '',
        ])
        ->call('save')
        ->assertHasNoErrors();

    // A frontend testing `if (analytics.ga_measurement_id)` must see absence, not an
    // empty string that renders an empty script tag.
    expect(Setting::get(Setting::GA_MEASUREMENT_ID))->toBeNull();
});

it('persists the contact details and publishes them to the Delivery API', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(Settings::class)
        ->fillForm([
            'site_name' => ['fa' => 'سایت آزمایشی'],
            'contact' => [
                'phone' => '+982112345678',
                'email' => 'info@example.test',
                'address' => ['fa' => 'تهران، خیابان نمونه'],
                'office_hours' => ['fa' => 'شنبه تا چهارشنبه ۹ تا ۱۷'],
                'form_labels' => ['fa' => ['name' => 'نام', 'email' => 'ایمیل']],
                'map_latitude' => '35.6892',
                'map_longitude' => '51.3890',
            ],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $contact = ContactSetting::current()->refresh();

    expect($contact->phone)->toBe('+982112345678')
        ->and($contact->email)->toBe('info@example.test')
        ->and($contact->getTranslation('address', 'fa'))->toBe('تهران، خیابان نمونه')
        ->and($contact->hasGeoCoordinates())->toBeTrue();

    $this->getJson('/api/v1/contact?locale=fa')
        ->assertOk()
        ->assertJsonPath('data.phone', '+982112345678')
        ->assertJsonPath('data.address', 'تهران، خیابان نمونه')
        ->assertJsonPath('data.form_labels.name', 'نام')
        ->assertJsonPath('data.map.latitude', 35.6892);
});

it('stores blank coordinates as null rather than zero', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(Settings::class)
        ->fillForm([
            'site_name' => ['fa' => 'سایت آزمایشی'],
            'contact' => ['map_latitude' => '', 'map_longitude' => ''],
        ])
        ->call('save')
        ->assertHasNoErrors();

    /*
     * 0,0 is a real place in the Gulf of Guinea. Casting blanks to 0.0 would make
     * hasGeoCoordinates() true and publish that as the client's address in the
     * LocalBusiness JSON-LD.
     */
    $contact = ContactSetting::current()->refresh();

    expect($contact->map_latitude)->toBeNull()
        ->and($contact->map_longitude)->toBeNull()
        ->and($contact->hasGeoCoordinates())->toBeFalse();
});

it('takes the Delivery API offline when maintenance mode is saved', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(Settings::class)
        ->fillForm([
            'site_name' => ['fa' => 'سایت آزمایشی'],
            'maintenance_mode' => true,
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::isMaintenanceMode())->toBeTrue();

    // 503 with Retry-After, which tells a crawler to come back rather than to
    // de-index — and the panel itself stays reachable, unlike `artisan down`.
    $this->getJson('/api/v1/settings')->assertStatus(503)->assertHeader('Retry-After');

    // The panel is NOT taken offline: the maintenance middleware is on the Delivery
    // routes only, which is the whole difference from `php artisan down`.
    expect($this->get(route('filament.admin.auth.login'))->status())->not->toBe(503);
});

it('names the panel after the site and keeps the panel out of search results', function (): void {
    Setting::put(Setting::SITE_NAME, ['fa' => 'پنل مشتری نمونه'], isTranslatable: true);

    /*
     * The login page, deliberately, and unauthenticated: it is the only panel page a
     * crawler can reach, so it is the one the noindex header has to cover — and it is
     * also where an administrator first sees which client's backoffice this is.
     */
    $this->get(route('filament.admin.auth.login'))
        ->assertSuccessful()
        // Read from the backend rather than config, so one Core copied per client
        // does not say "Laravel" in every header.
        ->assertSee('پنل مشتری نمونه', escape: false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
});

it('falls back to the app name when no site name is configured', function (): void {
    // A fresh install, before anyone opens Settings. The header must not be empty.
    expect(Setting::get(Setting::SITE_NAME))->toBeNull()
        ->and(SiteIdentity::name())->toBe(config('app.name'))
        // …but the JSON-LD must still omit the Organization rather than publish
        // "Laravel" as the publisher's name.
        ->and(SiteIdentity::translatedName())->toBeNull();
});

it('tells the admin which tab blocked the save, and saves nothing', function (): void {
    actingAs(User::factory()->admin()->create());

    Setting::put(Setting::GA_MEASUREMENT_ID, 'G-OLD');

    /*
     * The form is tabbed and the save is all-or-nothing, so a blank required site name
     * on one tab blocks an analytics id on another. Filament puts no error marker on a
     * tab and sends no notification of its own, so from where the admin is standing the
     * Save button simply did nothing and their edit was lost.
     */
    Livewire::test(Settings::class)
        ->fillForm([
            'site_name' => ['fa' => ''],
            'ga_measurement_id' => 'G-NEW',
        ])
        ->call('save')
        ->assertHasFormErrors(['site_name.fa'])
        // The admin is told so, and told which tab to open.
        ->assertNotified(__('cms.settings.validation_failed'));

    // Nothing was written — the save really is atomic across the tabs.
    expect(Setting::get(Setting::GA_MEASUREMENT_ID))->toBe('G-OLD');
});

it('keeps a site name stored under a locale the form does not render', function (): void {
    actingAs(User::factory()->admin()->create());

    /*
     * Setting::put() replaces the whole value and the form only renders
     * cms.locales.supported, so rebuilding the map from the form would delete this — a
     * client who narrows `supported` would lose a translation as a side effect of
     * editing an unrelated field. saveGeneral() merges instead.
     */
    Setting::put(Setting::SITE_NAME, ['fa' => 'اصلی', 'de' => 'Deutsch'], isTranslatable: true);

    Livewire::test(Settings::class)
        ->fillForm(['ga_measurement_id' => 'G-UNRELATED'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get(Setting::SITE_NAME))
        ->toBe(['fa' => 'اصلی', 'de' => 'Deutsch']);
});

it('clears a site name when its field is emptied', function (): void {
    actingAs(User::factory()->admin()->create());

    // Merging must not make a translation unremovable: blanking a rendered locale has
    // to delete it, or an editor can never withdraw an English name.
    Setting::put(Setting::SITE_NAME, ['fa' => 'اصلی', 'en' => 'Main'], isTranslatable: true);

    Livewire::test(Settings::class)
        ->fillForm(['site_name' => ['fa' => 'اصلی', 'en' => '']])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get(Setting::SITE_NAME))->toBe(['fa' => 'اصلی']);
});

it('refuses a social link whose scheme would never be published', function (): void {
    actingAs(User::factory()->admin()->create());

    /*
     * SiteIdentity publishes only http(s) entries, so a bare `url` rule — which accepts
     * ftp:// — let a link save with no complaint, never appear in the API or in
     * `sameAs`, and then be erased by the following save. The field has to refuse what
     * the reader will discard.
     */
    Livewire::test(Settings::class)
        ->fillForm([
            'site_name' => ['fa' => 'سایت آزمایشی'],
            'social_links' => [['url' => 'ftp://example.test/profile']],
        ])
        ->call('save')
        ->assertHasFormErrors();

    expect(Setting::get(Setting::SOCIAL_LINKS))->toBeNull();
});
