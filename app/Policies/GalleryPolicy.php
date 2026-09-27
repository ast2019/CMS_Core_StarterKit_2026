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

    /**
     * The other half of publish(), and it was missing.
     *
     * Laravel resolves an absent policy method to a DENIAL, so every non-admin was
     * refused — silently, because Gate::before still let an admin through. The panel's
     * bulk unpublish reported "0 changed, N skipped" to an Editor who could publish the
     * very same records, and each refusal wrote an authorisation-denial row into the
     * audit trail. Taking something down is the same decision as putting it up, so it
     * answers to the same ability.
     */
    public function unpublish(User $user, Gallery $gallery): bool
    {
        return $user->role->hasAbility('content.publish');
    }
}
