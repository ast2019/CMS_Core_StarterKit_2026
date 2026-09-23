<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Global settings are admin-only (Decision D-10): they include analytics codes,
 * search-console verification tokens and maintenance mode, which affect the whole
 * site rather than any one piece of content.
 *
 * Requirement 9.1.
 */
class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->hasAbility('settings.manage');
    }

    public function view(User $user, Model $setting): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role->hasAbility('settings.manage');
    }

    public function update(User $user, Model $setting): bool
    {
        return $user->role->hasAbility('settings.manage');
    }

    /**
     * Settings are created by the installer and updated thereafter. Deleting one
     * would make the application fall back to a config default silently, which is
     * harder to diagnose than an obviously wrong value.
     */
    public function delete(User $user, Model $setting): bool
    {
        return false;
    }
}
