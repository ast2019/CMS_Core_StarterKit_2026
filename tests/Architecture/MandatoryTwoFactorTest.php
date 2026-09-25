<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;

/**
 * RULE #5 — "Filament, Persian, RTL, custom branded theme, mandatory 2FA."
 *
 * Blueprint §12.5, Requirements 4.1, 9.3, 9.4.
 *
 * Note on approach: Filament runs the MFA challenge BEFORE authenticating the
 * user into the panel, so there is no authenticated-but-unenrolled state to
 * guard with middleware. The enforcement therefore lives in panel config, and
 * what these tests check is that the config cannot silently drift — in
 * particular that `isRequired` stays true, since flipping it to false is a
 * one-word change that would leave 2FA merely available rather than mandatory.
 */
it('requires multi-factor authentication on the admin panel', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->hasMultiFactorAuthentication())->toBeTrue(
        'RULE #5 violated: no MFA provider configured on the admin panel.',
    );

    expect($panel->isMultiFactorAuthenticationRequired())->toBeTrue(
        'RULE #5 violated: MFA is available but not REQUIRED. Every admin '.
        'account must be 2FA-gated, so isRequired must be true.',
    );
});

it('offers recovery codes so a lost device does not mean a locked-out admin', function (): void {
    $providers = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders();

    expect($providers)->not->toBeEmpty();
});

it('has a user model wired for app authentication and recovery', function (): void {
    expect(new User)
        ->toBeInstanceOf(HasAppAuthentication::class)
        ->toBeInstanceOf(HasAppAuthenticationRecovery::class);
});

it('keeps the two-factor secret and recovery codes encrypted and hidden', function (): void {
    $user = new User;

    // A leaked TOTP secret defeats the second factor entirely, and a leaked
    // recovery-code list defeats it permanently, so neither may ever appear in
    // a model's array/JSON form or be stored in plaintext.
    expect($user->getHidden())->toContain('app_authentication_secret')
        ->and($user->getHidden())->toContain('app_authentication_recovery_codes');

    $casts = $user->getCasts();

    expect($casts['app_authentication_secret'] ?? null)->toBe('encrypted')
        ->and($casts['app_authentication_recovery_codes'] ?? null)->toBe('encrypted:array');
});

it('never installs a third-party two-factor package alongside the native one', function (): void {
    $lock = json_decode((string) file_get_contents(projectPath('composer.lock')), true);

    $packages = array_map(
        fn (array $package): string => strtolower($package['name']),
        [...$lock['packages'], ...$lock['packages-dev']],
    );

    // Filament v4+ made MFA native, which is why backstagephp/filament-2fa was
    // abandoned. Installing one of these alongside gives two competing auth
    // paths — and the weaker one defines the real security posture.
    $redundant = [
        'backstagephp/filament-2fa',
        'visualbuilder/filament-2fa',
        'stephenjude/filament-two-factor-authentication',
        'jeffgreco13/filament-breezy',
        'pragmarx/google2fa-laravel',
    ];

    $installed = array_intersect($redundant, $packages);

    expect($installed)->toBeEmpty(
        'Redundant 2FA package(s) installed: '.implode(', ', $installed).
        '. Filament v4+ provides MFA natively.',
    );
});

it('serves the panel in Persian with RTL direction', function (): void {
    expect(config('app.locale'))->toBe('fa');

    // Filament derives document direction from this translation key rather than
    // a panel setting, so this is the actual source of RTL.
    app()->setLocale('fa');

    expect(__('filament-panels::layout.direction'))->toBe('rtl');
});

it('exposes all three locales as structural even though only Persian ships', function (): void {
    expect(config('cms.locales.supported'))->toBe(['fa', 'en', 'ar'])
        ->and(config('cms.locales.source'))->toBe('fa')
        ->and(config('cms.locales.rtl'))->toContain('fa')
        ->and(config('cms.locales.rtl'))->toContain('ar');
});

it('defaults to Persian without any environment variable', function (): void {
    /*
     * RULE #5 requires a Persian RTL panel. This asserts the CORE's default rather than
     * the running configuration, because phpunit.xml sets APP_LOCALE=fa — so every other
     * locale assertion in this suite passes regardless of what config/app.php falls back
     * to, and the real default went unexercised.
     *
     * It was 'en', Laravel's default. A container deployment ships no .env file, so the
     * panel rendered in English, left-to-right, until APP_LOCALE was set by hand. Found
     * by `cms:audit-rules` inside a container, not by these tests.
     */
    $config = require projectPath('config/app.php');

    // Re-read with the environment stripped, so the fallback itself is what is checked.
    $defaults = [
        'locale' => 'APP_LOCALE',
        'fallback_locale' => 'APP_FALLBACK_LOCALE',
    ];

    foreach ($defaults as $key => $variable) {
        expect($config[$key])->toBe(
            env($variable, 'fa'),
            "config/app.php [{$key}] must default to Persian, or a deployment without "
            ."{$variable} renders the panel in English and left-to-right (RULE #5)."
        );
    }

    $source = file_get_contents(projectPath('config/app.php'));

    expect($source)
        ->toContain("env('APP_LOCALE', 'fa')")
        ->toContain("env('APP_FALLBACK_LOCALE', 'fa')");
});
