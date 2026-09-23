<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCmsAbilities;

/**
 * Taxonomy is editorial structure, so it uses the content ability set.
 * Requirement 9.1.
 */
class CategoryPolicy
{
    use AuthorizesCmsAbilities;

    protected function abilityPrefix(): string
    {
        return 'content';
    }
}
