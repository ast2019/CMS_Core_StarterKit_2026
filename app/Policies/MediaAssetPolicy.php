<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\AuthorizesCmsAbilities;

/**
 * Requirement 9.1. An Author may manage only their own uploads.
 */
class MediaAssetPolicy
{
    use AuthorizesCmsAbilities;

    protected function abilityPrefix(): string
    {
        return 'media';
    }

    protected function ownerColumn(): ?string
    {
        return 'uploaded_by';
    }

    public function create(User $user): bool
    {
        return $user->role->hasAbility('media.upload');
    }
}
