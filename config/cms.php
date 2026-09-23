<?php

declare(strict_types=1);

use App\Enums\TranslationStatus;

return [

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | Every module is independently toggleable. A disabled module registers no
    | routes, no Filament resource, and contributes no sitemap entries. This is
    | enforced at the service-provider boundary, not with scattered if-checks.
    |
    | Requirement 1.1.
    |
    */

    'modules' => [
        'content' => env('CMS_MODULE_CONTENT', true),
        'category' => env('CMS_MODULE_CATEGORY', true),
        'tag' => env('CMS_MODULE_TAG', true),
        'gallery' => env('CMS_MODULE_GALLERY', true),
        'media' => env('CMS_MODULE_MEDIA', true),
        'slide' => env('CMS_MODULE_SLIDE', true),
        'page' => env('CMS_MODULE_PAGE', true),
        'contact' => env('CMS_MODULE_CONTACT', true),
        'menu' => env('CMS_MODULE_MENU', true),
        'settings' => env('CMS_MODULE_SETTINGS', true),
        'redirect' => env('CMS_MODULE_REDIRECT', true),
        'search' => env('CMS_MODULE_SEARCH', true),
        'seo' => env('CMS_MODULE_SEO', true),
        'sitemap' => env('CMS_MODULE_SITEMAP', true),
        'audit' => true, // RULE #8 — not toggleable, by design. No opt-out.
    ],

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | All three locales are structural from day one even though only Persian
    | content ships at launch. `source` is the authoring locale and the
    | x-default target for hreflang.
    |
    | Requirements 5.1, 5.2.
    |
    */

    'locales' => [
        'supported' => ['fa', 'en', 'ar'],
        'source' => env('CMS_SOURCE_LOCALE', 'fa'),
        'rtl' => ['fa', 'ar'],

        /*
         * Whether the Delivery API may infer the locale from Accept-Language when
         * the caller did not ask for one.
         *
         * Default OFF, which is the opposite of what feels natural. Three reasons:
         *
         *  1. This kit launches Persian-only with en/ar structural. With
         *     negotiation on, every English-speaking browser is served locale=en,
         *     which has no reviewed translation, so it receives fallback Persian
         *     flagged is_fallback — a worse default than simply serving the site's
         *     actual language.
         *  2. The frontend owns the URL structure (/fa/, /en/, /ar/) and passes the
         *     locale explicitly. Header inference only applies to callers that
         *     forgot, and silently guessing for them hides the omission.
         *  3. Responses are cached (Requirement 8.4). Varying on Accept-Language
         *     multiplies cache entries by the number of distinct header values a
         *     shared cache sees, which is effectively unbounded.
         *
         * Turn it on for a site whose frontend genuinely relies on content
         * negotiation.
         */
        'negotiate_from_header' => env('CMS_NEGOTIATE_LOCALE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Translation Lifecycle
    |--------------------------------------------------------------------------
    |
    | Statuses eligible for public listing and sitemap inclusion. Decision D-5:
    | unreviewed machine translation is excluded, because indexing it risks a
    | quality penalty across the whole locale.
    |
    | Requirement 5.6.
    |
    */

    'translation' => [
        'sitemap_eligible_statuses' => [
            TranslationStatus::Reviewed->value,
            TranslationStatus::Outdated->value,
        ],

        // Hash the source locale's translatable fields to detect staleness.
        // Hash-based rather than timestamp-based, so a no-op save does not
        // invalidate good translations. Requirement 5.4.
        'outdated_detection' => 'source_hash',
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | RULE #9 — local disk only. Changing `disk` to any cloud driver is a
    | defect; an architecture test asserts no S3 flysystem package is present.
    |
    | Requirements 2.3, 2.4, 2.6, 2.7.
    |
    */

    'media' => [
        'disk' => 'public',

        'conversions' => [
            'thumb' => 400,
            'medium' => 1024,
            'large' => 1920,
        ],

        // Require alt_text in the source locale before publishing. Req 2.7.
        'require_alt_text' => true,

        // ffprobe is needed only to derive a thumbnail/duration from a locally
        // uploaded video. Absent it, the panel demands a manual thumbnail.
        // Decision D-6.
        'ffprobe_path' => env('CMS_FFPROBE_PATH', 'ffprobe'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slides
    |--------------------------------------------------------------------------
    |
    | Blueprint §6 says "3-5"; the build brief says "max 5". Taken as a hard
    | cap of 5, enforced in validation. Requirements 3.4, 3.5.
    |
    */

    'slides' => [
        'max' => env('CMS_SLIDES_MAX', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | APIs
    |--------------------------------------------------------------------------
    |
    | Decision D-9: the Delivery API is public by default. The API-key
    | infrastructure exists so a client can lock it down without
    | re-engineering, but enabling it makes responses cacheable per-key only.
    |
    | Requirements 8.1-8.7.
    |
    */

    'api' => [
        'version' => 'v1',

        'delivery' => [
            'require_key' => env('CMS_DELIVERY_REQUIRE_KEY', false),
            'key' => env('CMS_DELIVERY_API_KEY'),
            'rate_limit' => env('CMS_DELIVERY_RATE_LIMIT', 120),
            'cache_ttl' => env('CMS_DELIVERY_CACHE_TTL', 300),
        ],

        'management' => [
            'rate_limit' => env('CMS_MANAGEMENT_RATE_LIMIT', 60),
            'ability' => 'manage',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Draft Preview
    |--------------------------------------------------------------------------
    |
    | Requirements 4.6, 4.7.
    |
    */

    'preview' => [
        'ttl_minutes' => env('CMS_PREVIEW_TTL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Related Content
    |--------------------------------------------------------------------------
    |
    | Auto-suggested from shared categories and tags. Requirement 4.8.
    |
    */

    'related' => [
        'limit' => 6,
        'weight_category' => 2,
        'weight_tag' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Versions
    |--------------------------------------------------------------------------
    |
    | Distinct from the audit log: versions are restorable editorial snapshots
    | and are prunable. The audit log is append-only and never pruned.
    |
    | Requirement 3.7.
    |
    */

    'versions' => [
        'keep' => env('CMS_VERSIONS_KEEP', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | Defaults only. Never hardcode a client value here — per-site overrides go
    | in the Settings singleton or the site's own .env. Requirement 1.2.
    |
    */

    'brand' => [
        'primary' => env('CMS_BRAND_PRIMARY', '#0F766E'),
        'panel_path' => env('CMS_PANEL_PATH', 'admin'),
    ],

];
