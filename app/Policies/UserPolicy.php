<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Requirement 9.1.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->hasAbility('user.manage');
    }

    public function view(User $user, User $target): bool
    {
        // Anyone may view their own record (the profile page needs it).
        return $user->is($target) || $user->role->hasAbility('user.manage');
    }

    public function create(User $user): bool
    {
        return $user->role->hasAbility('user.manage');
    }

    public function update(User $user, User $target): bool
    {
        return $user->is($target) || $user->role->hasAbility('user.manage');
    }

    /**
     * An admin must not delete or deactivate their own account.
     *
     * With MFA mandatory and admin the only role that can manage users, a
     * single-admin installation where that admin deletes themselves is
     * unrecoverable without direct database access.
     */
    public function delete(User $user, User $target): bool
    {
        if ($user->is($target)) {
            return false;
        }

        return $user->role->hasAbility('user.manage');
    }

    public function deactivate(User $user, User $target): bool
    {
        return $this->delete($user, $target);
    }

    /**
     * Changing someone else's role is admin-only, and never your own — a user who
     * could raise their own role would make the whole matrix advisory.
     */
    public function assignRole(User $user, User $target): bool
    {
        return ! $user->is($target) && $user->role->hasAbility('user.manage');
    }
}
