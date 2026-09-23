<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\TranslationStatus;

/**
 * A model whose per-locale translations have a reviewable lifecycle.
 *
 * Requirements 5.3, 5.4, 5.6.
 *
 * Paired with HasTranslationStatus. Declaring it as an interface lets the SEO and
 * sitemap layers branch on capability with a type check instead of
 * method_exists(), so a model that gains translatable fields but not the status
 * lifecycle is a visible omission rather than a silent "eligible for everything".
 */
interface TracksTranslationStatus
{
    public function translationStatusFor(string $locale): TranslationStatus;

    /**
     * Whether this record may appear in a locale's sitemap (Decision D-5).
     */
    public function isSitemapEligibleFor(string $locale): bool;

    public function sourceContentHash(): string;
}
