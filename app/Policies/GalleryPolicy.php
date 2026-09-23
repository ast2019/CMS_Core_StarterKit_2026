<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Gallery;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCmsAbilities;

/**
 * Galleries follow the content ability set: they are content-bearing, carry a
 * featured image (RULE #7) and move through the publish workflow.
 *
 * Requirement 9.1.
 */
class GalleryPolicy
{
    use AuthorizesCmsAbilities;

    protected function abilityPrefix(): string
    {
        return 'content';
    }

    public function publish(User $user, Gallery $gallery): bool
    {
        return $user->role->hasAbility('content.publish');
    }
}
