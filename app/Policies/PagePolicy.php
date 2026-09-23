<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Page;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCmsAbilities;
use Illuminate\Database\Eloquent\Model;

/**
 * Requirements 3.8, 9.1.
 */
class PagePolicy
{
    use AuthorizesCmsAbilities;

    protected function abilityPrefix(): string
    {
        return 'content';
    }

    /**
     * System pages (the 404, maintenance) cannot be deleted by anyone, including
     * an admin.
     *
     * Requirement 3.8 makes the 404 page a Page record so it is brandable — but
     * that also makes it deletable by an editor tidying up a page list, who has
     * no reason to expect that the site would lose its error page entirely.
     * Deletion is blocked at the policy so both the panel and the Management API
     * are covered.
     */
    public function delete(User $user, Model $page): bool
    {
        if ($page instanceof Page && $page->isSystemPage()) {
            return false;
        }

        return $user->role->hasAbility('content.delete');
    }

    public function forceDelete(User $user, Model $page): bool
    {
        if ($page instanceof Page && $page->isSystemPage()) {
            return false;
        }

        return $user->role->isAdmin();
    }

    public function publish(User $user, Page $page): bool
    {
        return $user->role->hasAbility('content.publish');
    }
}
