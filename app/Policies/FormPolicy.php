<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Form;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Item 15 — who may build forms.
 *
 * `settings.manage`, so Admin only, rather than a new ability. A form decides what personal data
 * the public site collects and what a public endpoint accepts, which is the same order of
 * decision as the contact details and analytics codes already behind that ability — and the
 * contact form's wording used to live on the Settings page (`form_labels`). Reading what was
 * SENT stays on `contact.view`, through the inbox; building the questions is a separate job.
 *
 * Note that Gate::before lets Admins past every method here, so the rules that must hold even
 * for them — the contact form cannot be deleted, a form with submissions cannot be deleted — are
 * enforced on the model (Form::guardDeletion) and mirrored in the panel's action visibility, not
 * left to delete() below.
 */
class FormPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->hasAbility('settings.manage');
    }

    public function view(User $user, Model $form): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role->hasAbility('settings.manage');
    }

    public function update(User $user, Model $form): bool
    {
        return $user->role->hasAbility('settings.manage');
    }

    public function delete(User $user, Model $form): bool
    {
        return $user->role->hasAbility('settings.manage')
            && $form instanceof Form
            && $form->isDeletable();
    }
}
