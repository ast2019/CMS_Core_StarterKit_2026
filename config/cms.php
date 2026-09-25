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
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | Base URL of the PUBLIC site, which is a separate deployment: this Core is
    | headless (blueprint §1), so canonical URLs, hreflang annotations and sitemap
    | entries must point at the frontend rather than at this API host.
    |
    | Left empty it falls back to APP_URL, which is correct for a single-host setup.
    | Getting this wrong is quietly expensive — a sitemap full of API URLs would be
    | submitted to Search Console and index the wrong hostname.
    |
    */

    'frontend_url' => env('CMS_FRONTEND_URL'),

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
    | AI Translation
    |--------------------------------------------------------------------------
    |
    | Machine translation of a record's source-locale fields into a target locale
    | via OpenRouter (Requirement 5.3 — the ai_translated stage of the lifecycle).
    |
    | Only DEFAULTS live here. The three operational values — whether the feature
    | is enabled, which model to use, and the API key — are edited by an
    | administrator on the Settings page and stored in the `settings` table, not
    | in code or .env, so one Core can be copied per client without a redeploy
    | (Requirement 1.2). The key in particular must never live in a committed
    | file. `default_model` is used only as the fallback when the admin has not
    | overridden it; the admin can change the model afterwards.
    |
    */

    'ai' => [
        'translation' => [
            // Fallback model. The admin can override this on the Settings page.
            'default_model' => 'openai/gpt-4o-mini',

            // OpenRouter's OpenAI-compatible chat-completions endpoint. Not a
            // client-specific value, so it is safe to keep in code.
            'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',

            // Seconds to wait on the outbound call before failing gracefully.
            'timeout' => 30,
        ],
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
    | Menu Locations
    |--------------------------------------------------------------------------
    |
    | The navigation regions this deployment has. One `menu_items` table serves all
    | of them, grouped by `menu_key` — so a location is METADATA about the frontend's
    | layout, not content, and deliberately has no model or table of its own. A
    | `menus` table would have to be seeded per deployment, would let an editor create
    | a location the frontend has no slot to render, and would answer no question this
    | list does not.
    |
    | Declared here rather than hardcoded in App\Models\MenuItem so a client site can
    | add a "utility" bar or drop the sidebar without patching the Core (Requirement
    | 1.2). The same list does three jobs, which is why it is one list:
    |   - it populates the location Select and the table filter in the panel;
    |   - it VALIDATES `menu_key` on save, so a seed or an import cannot write items
    |     into a menu nothing renders;
    |   - it decides whether GET /api/v1/menus/{key} is a 404. An undeclared location
    |     is a frontend typo and now says so, instead of being indistinguishable from
    |     a declared menu that happens to be empty.
    |
    | Labels are NOT here. The panel is trilingual, so a literal label in config would
    | force one language on a field the rest of the panel translates, and config is
    | resolved (and cached) independently of the active locale. Each key is labelled
    | from `cms.menu.location.{key}` in the lang files, falling back to the raw key —
    | so a client can add a location and ship without touching lang files at all.
    |
    | Requirement 3.1.
    |
    */

    'menus' => [
        'locations' => ['header', 'footer', 'sidebar'],
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

            /*
             * Redirect lookups get their own, much higher allowance.
             *
             * Not generosity — a correction for how the traffic actually arrives. The
             * delivery limiter is keyed by IP, which is right for browsers hitting the
             * API directly. But the redirect lookup is called by the FRONTEND server on
             * every 404 it serves, from one IP for the entire site's traffic, so the
             * shared 120/min ceiling would throttle the whole deployment the moment a
             * crawler walked a few stale URLs — and the failure mode is that redirects
             * stop working, which is the bug this endpoint exists to fix.
             *
             * The work behind each call is an in-memory lookup against one cached map,
             * so a high limit is cheap. It is still a limit: without one, a 404 flood
             * would drive an unbounded number of hit-counter UPDATEs.
             */
            'redirect_rate_limit' => env('CMS_REDIRECT_RATE_LIMIT', 600),
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
    | Release
    |--------------------------------------------------------------------------
    |
    | RULE #1 — where cms:release writes the human-readable changelog.
    |
    | Configurable so the test suite can point it at a temporary file. Without that
    | the release tests append to the repository's own CHANGELOG.md on every run,
    | which pollutes a tracked file with fake entries and duplicate versions — and
    | the pollution is easy to commit by accident.
    |
    */

    'changelog_path' => env('CMS_CHANGELOG_PATH') ?: base_path('CHANGELOG.md'),

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
