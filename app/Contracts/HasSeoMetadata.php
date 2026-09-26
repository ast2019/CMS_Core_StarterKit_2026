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

    /**
     * SEO attributes every implementing model MUST declare as translatable.
     *
     * Asserted by tests/Architecture/SeoMetaTest.php, which is what makes this a rule
     * rather than a comment: a model could otherwise use HasSeoMeta with
     * `meta_description` as a plain column, and all three locales would silently share
     * one Persian description. That failure is invisible in the panel — the field
     * saves and redisplays — and surfaces only as the same Persian snippet under the
     * English and Arabic results.
     *
     * Declared on the contract for the same reason the limits above are, and it was
     * found the same way: as a trait constant the list was unreadable to the test that
     * enforces it.
     *
     * @var list<string>
     */
    public const SEO_TRANSLATABLE_ATTRIBUTES = [
        'meta_title',
        'meta_description',
        'robots_meta',
    ];

    /**
     * SEO attributes a model MAY carry, and which must be translatable when it does.
     *
     * A second list rather than three more entries in the one above, because that one
     * is a requirement on all four models and these three are genuinely optional:
     *
     *  - `focus_keyphrase` is an authoring aid for PROSE, so it is on Content and
     *    Page. A Gallery is an image set whose text is a caption, and a Category is an
     *    archive page whose ranking comes from the articles it lists rather than from
     *    its own copy — a keyphrase field on either would be a box to fill in with no
     *    check behind it worth acting on.
     *  - `og_title` / `og_description` override the social card, which the Delivery
     *    API currently builds for articles only.
     *
     * Forcing all three onto four tables would add columns nothing reads. The
     * architecture test therefore asserts the weaker, true rule: a model that HAS one
     * of these columns must declare it translatable AND fillable. Adding a keyphrase
     * to Gallery later is then a migration plus one line in $translatable, with the
     * panel picking it up on its own — SeoSection asks the model rather than carrying
     * a list of its own.
     *
     * @var list<string>
     */
    public const OPTIONAL_SEO_TRANSLATABLE_ATTRIBUTES = [
        'focus_keyphrase',
        'og_title',
        'og_description',
    ];

    public function metaTitleFor(string $locale): string;

    public function metaDescriptionFor(string $locale): string;

    public function robotsMetaFor(string $locale): string;

    /**
     * Social-card title/description: the per-record override if set, else the meta
     * value. Declared here rather than left on the trait for the same reason the
     * advisory limits are: the tag builder is generic over the model.
     */
    public function ogTitleFor(string $locale): string;

    public function ogDescriptionFor(string $locale): string;

    public function focusKeyphraseFor(string $locale): string;

    /**
     * Whether this model carries a focus keyphrase. Not every SEO-bearing model
     * does — see HasSeoMeta::OPTIONAL_SEO_TRANSLATABLE_ATTRIBUTES — and both the
     * panel and the API decide what to render by asking rather than by keeping a
     * list of model classes that would drift.
     */
    public function seoSupportsFocusKeyphrase(): bool;

    /**
     * Focus-keyphrase and content analysis for a locale.
     *
     * @return array{
     *     keyphrase: string,
     *     score: int|null,
     *     band: string|null,
     *     checks: list<array{id: string, status: string, value: string|null}>
     * }
     */
    public function seoAnalysisFor(string $locale): array;

    /**
     * Advisory warnings for the panel.
     *
     * @return list<string>
     */
    public function seoWarningsFor(string $locale): array;

    public function isPubliclyVisible(): bool;
}
