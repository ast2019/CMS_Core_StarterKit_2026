<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared policy logic for the D-10 ability matrix.
 *
 * Requirements 9.1, 9.2.
 *
 * Note on Gate::before in CmsServiceProvider: an inactive account is denied
 * before any policy method runs, and an admin is allowed. So these methods only
 * ever execute for active non-admin users, which is why none of them re-check
 * `is_active`.
 */
trait AuthorizesCmsAbilities
{
    /**
     * Ability namespace for the model this policy guards, e.g. 'content'.
     */
    abstract protected function abilityPrefix(): string;

    /**
     * Column naming the owner, or null when the model has no concept of one.
     */
    protected function ownerColumn(): ?string
    {
        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->role->hasAbility($this->abilityPrefix().'.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role->hasAbility($this->abilityPrefix().'.create');
    }

    /**
     * An Author may edit only their own work; an Editor may edit anyone's.
     *
     * The `.own` ability is checked against the owner column, so a model with no
     * owner (a Category, say) can never be edited under `.own` alone — otherwise
     * granting an Author `.own` on a model without an author would silently give
     * them write access to every row.
     */
    public function update(User $user, Model $model): bool
    {
        $prefix = $this->abilityPrefix();

        if ($user->role->hasAbility($prefix.'.update.any')) {
            return true;
        }

        if (! $user->role->hasAbility($prefix.'.update.own')) {
            return false;
        }

        return $this->owns($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->role->hasAbility($this->abilityPrefix().'.delete');
    }

    public function restore(User $user, Model $model): bool
    {
        return $user->role->hasAbility($this->abilityPrefix().'.restore');
    }

    public function forceDelete(User $user, Model $model): bool
    {
        // Permanent deletion is admin-only regardless of role abilities: it
        // destroys the audit subject along with the record.
        return $user->role->isAdmin();
    }

    protected function owns(User $user, Model $model): bool
    {
        $column = $this->ownerColumn();

        if ($column === null) {
            return false;
        }

        return (int) $model->getAttribute($column) === (int) $user->getKey();
    }
}
