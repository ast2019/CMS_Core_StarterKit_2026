<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Requirement 9.1.
 */
class MenuItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->hasAbility('menu.manage');
    }

    public function view(User $user, Model $item): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role->hasAbility('menu.manage');
    }

    public function update(User $user, Model $item): bool
    {
        return $user->role->hasAbility('menu.manage');
    }

    public function delete(User $user, Model $item): bool
    {
        return $user->role->hasAbility('menu.manage');
    }

    /**
     * Item 11 — menu items now have a trash, so they need the two methods that go with one.
     *
     * Hand-written rather than inherited from AuthorizesCmsAbilities, like the rest of this
     * policy: navigation is gated on the single `menu.manage` ability rather than on a
     * view/create/update/delete family, so there is no `menu.restore` to check. Anyone who may
     * delete a menu item may undo that, which is the only sensible reading of a trash for a
     * permission that is already all-or-nothing.
     *
     * Without these, Filament resolved both actions to DENY and the restore button was
     * invisible — a trash nobody could empty or recover from.
     */
    public function restore(User $user, Model $item): bool
    {
        return $user->role->hasAbility('menu.manage');
    }

    /**
     * Permanent deletion is admin-only, matching AuthorizesCmsAbilities::forceDelete(): it
     * destroys the audit subject along with the record.
     */
    public function forceDelete(User $user, Model $item): bool
    {
        return $user->role->isAdmin();
    }
}
