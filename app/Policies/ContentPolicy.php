<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Content;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCmsAbilities;

/**
 * Requirements 9.1, 9.2. Decision D-10.
 */
class ContentPolicy
{
    use AuthorizesCmsAbilities;

    protected function abilityPrefix(): string
    {
        return 'content';
    }

    protected function ownerColumn(): ?string
    {
        return 'author_id';
    }

    /**
     * Publishing is separate from updating on purpose: an Author can write and
     * revise an article but cannot put it live, which is the whole point of the
     * draft -> review -> published workflow.
     */
    public function publish(User $user, Content $content): bool
    {
        return $user->role->hasAbility('content.publish');
    }

    public function unpublish(User $user, Content $content): bool
    {
        return $this->publish($user, $content);
    }

    public function reviewTranslation(User $user, Content $content): bool
    {
        return $user->role->hasAbility('translation.review');
    }

    /**
     * Generating a preview link for unpublished content is limited to users who
     * could see that content in the panel anyway — a signed URL bypasses
     * authentication for whoever holds it (Requirements 4.6, 4.7), so handing
     * one out must not be a lower bar than viewing the draft.
     */
    public function preview(User $user, Content $content): bool
    {
        return $this->update($user, $content) || $user->role->hasAbility('content.publish');
    }
}
