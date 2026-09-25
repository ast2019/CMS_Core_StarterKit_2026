<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Widgets\VersionWidget;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\EditProfile;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * RULE #5 — Filament, Persian, RTL, custom branded theme, mandatory 2FA.
 *
 * RTL note: Filament derives the document direction from the
 * `filament-panels::layout.direction` translation key, and its bundled Persian
 * translations already set it to 'rtl'. So RTL follows from APP_LOCALE=fa
 * rather than from any explicit direction call — there is no ->direction()
 * method on the panel, and hand-rolling one would fight the framework.
 *
 * Requirements 4.1, 4.2, 4.3, 9.3, 9.4.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path((string) config('cms.brand.panel_path', 'admin'))
            ->login()
            ->passwordReset()
            ->profile(EditProfile::class, isSimple: false)

            /*
             * RULE #5 — MANDATORY 2FA.
             *
             * Filament's native multi-factor support (v4+); no third-party 2FA
             * plugin is installed or needed. `isRequired: true` means a user
             * who has not enrolled is diverted to the setup page straight after
             * sign-in and cannot reach any other panel page.
             *
             * The challenge runs BEFORE the user is authenticated into the
             * panel, so no custom "force enrolment" middleware is required —
             * an earlier design draft specified one and it would have been dead
             * code guarding an already-closed door.
             *
             * Recovery codes are enabled deliberately: without them, a lost
             * authenticator device means a locked-out admin and a manual
             * database edit to recover.
             */
            ->multiFactorAuthentication(
                AppAuthentication::make()
                    ->recoverable()
                    ->recoveryCodeCount(8)
                    ->brandName(config('app.name')),
                isRequired: true,
            )

            /*
             * RULE #4 — ADMIN FONT.
             *
             * LocalFontProvider is passed explicitly. Filament's default font
             * provider is GoogleFontProvider, so omitting this argument would
             * silently emit a fonts.googleapis.com stylesheet link and break
             * the no-external-CDN rule. The architecture test asserts this
             * provider is the one configured.
             */
            ->font(
                'Vazirmatn',
                url: asset('css/vazirmatn.css'),
                provider: LocalFontProvider::class,
            )

            // Custom branded theme. Brand colour is configurable per site so
            // the Core carries no client-specific value (Requirement 1.2).
            ->colors([
                'primary' => Color::hex((string) config('cms.brand.primary', '#0F766E')),
                'gray' => Color::Slate,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')

            /*
             * Database notifications, for work that outlives the request.
             *
             * AI translation is queued (App\Jobs\TranslateRecordJob): up to six
             * sequential model calls, minutes after the click. A flash notification
             * cannot report that outcome because the request is long gone, so the
             * job writes to the `notifications` table and the bell surfaces it the
             * next time the translator looks at the panel.
             *
             * Polling is switched OFF explicitly (Filament's default is every 30s).
             * A poll costs one query per open tab per interval for the whole team,
             * forever, to shorten the wait on a job that takes minutes anyway. The
             * bell updates on the next page load, which for this workflow — queue a
             * translation, carry on translating something else — is soon enough.
             */
            ->databaseNotifications()
            ->databaseNotificationsPolling(null)

            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                // RULE #2 — the version is shown in the panel, to every role: it is the
                // first thing anyone needs when reporting a problem.
                VersionWidget::class,
            ])

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
