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
}
