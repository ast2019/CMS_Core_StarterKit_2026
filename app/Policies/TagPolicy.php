<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCmsAbilities;

/**
 * Requirement 9.1.
 */
class TagPolicy
{
    use AuthorizesCmsAbilities;

    protected function abilityPrefix(): string
    {
        return 'content';
    }
}
