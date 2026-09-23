<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Requirement 7.5.
 */
enum RedirectType: int
{
    case Permanent = 301;
    case Temporary = 302;

    public function label(): string
    {
        return match ($this) {
            self::Permanent => __('cms.redirect.permanent'),
            self::Temporary => __('cms.redirect.temporary'),
        };
    }

    public function statusCode(): int
    {
        return $this->value;
    }
}
