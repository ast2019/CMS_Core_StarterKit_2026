<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A model that carries per-locale SEO metadata.
 *
 * Requirements 7.1, 7.3. Implemented by Content, Page, Gallery and Category, all
 * of which get the behaviour from App\Concerns\HasSeoMeta.
 *
 * ---------------------------------------------------------------------------
 * Why the advisory limits live here and not on the trait
 * ---------------------------------------------------------------------------
 * They used to be `public const` on HasSeoMeta, which made them unreachable to
 * every caller outside it: PHP does not allow a trait constant to be read through
 * the trait's own name, only through a class that uses it. So anything wanting the
 * numbers had to either reach through an arbitrary model (`Content::` — which
 * reads as though the limit were an article-specific rule) or hardcode 60 and 155
 * and let the two drift apart. The panel needed them, and that is what pushed
 * them onto a contract.
 *
 * Declaring them here keeps `Content::META_TITLE_ADVISORY_LIMIT` working exactly
 * as before — a class inherits its interface's constants — while also making
 * `HasSeoMetadata::META_TITLE_ADVISORY_LIMIT` legal for callers that are generic
 * over the model, such as the shared SEO form section.
 */
interface HasSeoMetadata
{
    /**
     * Google truncates title tags at roughly 60 characters and descriptions at
     * roughly 155. These are advisory limits surfaced in the panel, not hard
     * validation — a deliberately longer title is a legitimate editorial choice.
     */
    public const META_TITLE_ADVISORY_LIMIT = 60;

    public const META_DESCRIPTION_ADVISORY_LIMIT = 155;

    public function metaTitleFor(string $locale): string;

    public function metaDescriptionFor(string $locale): string;

    public function robotsMetaFor(string $locale): string;

    /**
     * Advisory warnings for the panel.
     *
     * @return list<string>
     */
    public function seoWarningsFor(string $locale): array;

    public function isPubliclyVisible(): bool;
}
