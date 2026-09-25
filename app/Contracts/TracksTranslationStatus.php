<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\TranslationStatus;
use App\Models\TranslationState;

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

    /**
     * Whether a locale holds any translated text at all.
     *
     * Part of the contract because the review UI must refuse to sign off on an
     * empty locale: marking it reviewed would make it sitemap-eligible and publish
     * a blank page. A caller holding only a Model needs to ask this before acting.
     */
    public function hasAnyTranslationFor(string $locale): bool;

    /**
     * Mark a locale reviewed, pinning the source hash it was verified against
     * (Requirement 5.4).
     */
    public function markTranslationReviewed(string $locale, ?int $userId = null): TranslationState;

    /**
     * Record that a locale now holds machine-translated text awaiting review
     * (Requirement 5.3). Called by the AI translator after writing translations.
     */
    public function markTranslationAiTranslated(string $locale): TranslationState;

    public function sourceLocale(): string;
}
