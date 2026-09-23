<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * RULE #5 — mandatory 2FA on every admin account.
 *
 * The MFA contracts below are Filament's native multi-factor support (v4+), not
 * a third-party plugin. Filament performs the MFA challenge *before* the user is
 * actually authenticated into the panel, so no "force enrolment" middleware is
 * needed — an earlier draft of the design specified one, and it would have been
 * redundant surface area. With `isRequired: true` on the panel, a user who has
 * not set up MFA is sent to the setup page immediately after sign-in and cannot
 * reach any other panel page.
 *
 * Requirements 9.1, 9.3, 9.4.
 *
 * Property annotations are load-bearing, not decoration: `role` is cast to a
 * UserRole enum in casts(), which static analysis cannot infer, so without the
 * annotation every `$user->role->hasAbility(...)` in every Policy reads as a
 * method call on a string.
 *
 * @property UserRole $role
 * @property bool $is_active
 * @property string $name
 * @property string $email
 * @property string|null $app_authentication_secret
 * @property array<int, string>|null $app_authentication_recovery_codes
 */
#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;
    use Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Requirement 9.1 — only users holding `panel.access` may reach the panel,
     * and a deactivated account is refused regardless of role.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->role->hasAbility('panel.access');
    }

    /**
     * @return HasMany<Content, $this>
     */
    public function contents(): HasMany
    {
        return $this->hasMany(Content::class, 'author_id');
    }

    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    /**
     * Whether this account has completed MFA enrolment. Used by the security
     * audit report in the panel, not for access control — access control is
     * Filament's, via `isRequired: true`.
     */
    public function hasCompletedMfaEnrolment(): bool
    {
        return filled($this->getAppAuthenticationSecret());
    }
}
