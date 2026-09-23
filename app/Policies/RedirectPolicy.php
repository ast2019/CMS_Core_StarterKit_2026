<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Requirements 7.5, 9.1. Decision D-10.
 *
 * Editors hold `redirect.manage`, which was a deliberate change from the
 * originally proposed matrix. Editors can change slugs, and a slug change on
 * published content auto-suggests a 301 — an editor able to create the condition
 * but not the remedy would leave broken links behind and need an admin to finish
 * routine work.
 */
class RedirectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->hasAbility('redirect.manage');
    }

    public function view(User $user, Model $redirect): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role->hasAbility('redirect.manage');
    }

    public function update(User $user, Model $redirect): bool
    {
        return $user->role->hasAbility('redirect.manage');
    }

    public function delete(User $user, Model $redirect): bool
    {
        return $user->role->hasAbility('redirect.manage');
    }
}
