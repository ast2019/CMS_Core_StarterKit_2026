<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Per-locale translation lifecycle.
 *
 *   not_translated -> ai_translated -> reviewed -> outdated
 *                                          ^           |
 *                                          +-----------+
 *
 * `outdated` is reachable only from `reviewed`: it means "this was verified
 * once, but the source has changed since". Content that was never reviewed
 * cannot become outdated, it is simply still unreviewed.
 *
 * Requirements 5.3, 5.4, 5.6.
 */
enum TranslationStatus: string
{
    case NotTranslated = 'not_translated';
    case AiTranslated = 'ai_translated';
    case Reviewed = 'reviewed';
    case Outdated = 'outdated';

    public function label(): string
    {
        return match ($this) {
            self::NotTranslated => __('cms.translation.not_translated'),
            self::AiTranslated => __('cms.translation.ai_translated'),
            self::Reviewed => __('cms.translation.reviewed'),
            self::Outdated => __('cms.translation.outdated'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotTranslated => 'gray',
            self::AiTranslated => 'info',
            self::Reviewed => 'success',
            self::Outdated => 'warning',
        };
    }

    /**
     * Decision D-5: only reviewed and outdated translations are eligible for a
     * locale's sitemap. Unreviewed machine output is excluded deliberately.
     */
    public function isSitemapEligible(): bool
    {
        return in_array(
            $this->value,
            config('cms.translation.sitemap_eligible_statuses', []),
            strict: true,
        );
    }

    /**
     * Whether a human has signed off on this translation at some point.
     * Only such translations can go stale.
     */
    public function wasReviewed(): bool
    {
        return in_array($this, [self::Reviewed, self::Outdated], strict: true);
    }
}
