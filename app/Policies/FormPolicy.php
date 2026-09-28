<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Form;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Item 15 — who may build forms: anyone holding `form.manage`, which is Admin, Editor and Author.
 *
 * The owner's decision (after 0.9.0), replacing Admin-only `settings.manage`: a form's wording is
 * editorial copy. Forms have no owner column, so there is no `.own` / `.any` split — any holder
 * may edit, deactivate, re-key or (when it has no submissions) delete ANY form, a colleague's
 * included. That is the first right an Author has over other people's work, and it was accepted
 * knowingly: Form is audited, and the contact form's structure stays locked for everyone
 * (ContactFormStructure). Do not narrow it to `.own` without asking the owner. Reading what was
 * SENT stays on `contact.view`, through the inbox.
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
        return $user->role->hasAbility('form.manage');
    }

    public function view(User $user, Model $form): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role->hasAbility('form.manage');
    }

    public function update(User $user, Model $form): bool
    {
        return $user->role->hasAbility('form.manage');
    }

    public function delete(User $user, Model $form): bool
    {
        return $user->role->hasAbility('form.manage')
            && $form instanceof Form
            && $form->isDeletable();
    }
}
