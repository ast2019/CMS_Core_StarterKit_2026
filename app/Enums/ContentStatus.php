<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Editorial workflow: draft -> review -> published -> archived, with scheduling.
 *
 * Requirement 3.6.
 */
enum ContentStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('cms.status.draft'),
            self::Review => __('cms.status.review'),
            self::Published => __('cms.status.published'),
            self::Archived => __('cms.status.archived'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Review => 'warning',
            self::Published => 'success',
            self::Archived => 'danger',
        };
    }

    /**
     * Which statuses this one may legally transition to. Enforced by the
     * publish workflow so content cannot jump from draft straight to archived.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Review, self::Published],
            self::Review => [self::Draft, self::Published],
            self::Published => [self::Draft, self::Archived],
            self::Archived => [self::Draft],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /**
     * Only published content is publicly readable. Note this is status alone —
     * scheduling is applied separately, since a published record with a future
     * publish_date is not yet live.
     */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }
}
