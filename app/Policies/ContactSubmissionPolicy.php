<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Requirement 9.1.
 */
class ContactSubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->hasAbility('contact.view');
    }

    public function view(User $user, Model $submission): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Submissions arrive from the public form, never from the panel.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Inbound messages are a record of what a visitor actually sent, so they are
     * not editable — only markable as read, which is a separate action.
     */
    public function update(User $user, Model $submission): bool
    {
        return false;
    }

    public function markRead(User $user, Model $submission): bool
    {
        return $user->role->hasAbility('contact.view');
    }

    /**
     * Deletion is admin-only: submissions may contain personal data subject to a
     * retention policy, and that decision should not sit with every editor.
     */
    public function delete(User $user, Model $submission): bool
    {
        return $user->role->isAdmin();
    }
}
