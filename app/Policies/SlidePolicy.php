<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Slide;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCmsAbilities;

/**
 * Requirements 3.4, 3.5, 9.1.
 */
class SlidePolicy
{
    use AuthorizesCmsAbilities;

    protected function abilityPrefix(): string
    {
        return 'content';
    }

    /**
     * Requirement 3.5 — the cap is enforced here as well as in validation, so
     * the Management API cannot create a sixth active slide by skipping the form.
     */
    public function create(User $user): bool
    {
        return $user->role->hasAbility('content.create') && Slide::canAddActiveSlide();
    }
}
