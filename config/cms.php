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
    | Dates & Calendars
    |--------------------------------------------------------------------------
    |
    | Which calendar, which digits and which timezone a date is DISPLAYED in,
    | per locale. Storage is untouched by everything here: the database keeps UTC
    | Gregorian timestamps and the Delivery API keeps emitting ISO-8601, because a
    | machine-readable instant must stay machine-readable (see `api` below).
    |
    | Implemented with ext-intl / ICU rather than a Jalali PHP package. ICU is
    | already a hard requirement of this image (the Dockerfile installs it for
    | "locale-aware formatting for fa/en/ar"), it ships the month names, weekday
    | names and digit shapes for all three locales, and its Persian calendar is
    | the astronomical one — so 1403 correctly has a 30th of Esfand, which the
    | common 33-year-cycle approximations get wrong.
    |
    */

    'dates' => [
        /*
         * The timezone dates are rendered in, and that the admin's date pickers
         * read and write.
         *
         * SEPARATE FROM config('app.timezone'), which stays UTC — storing local
         * time is how a site ends up with ambiguous rows across a DST change.
         * This is a display concern only.
         *
         * It matters more than it looks for Jalali: Tehran is UTC+3:30, so
         * anything published after 20:30 UTC already belongs to the NEXT Persian
         * day. Formatting a UTC instant without shifting it first shows editors
         * the wrong date for every evening publication.
         */
        'timezone' => env('CMS_DISPLAY_TIMEZONE', 'Asia/Tehran'),

        /*
         * ICU calendar per locale.
         *
         * `ar` is GREGORIAN on purpose. Arabic is a language, not a calendar:
         * news and civil dates across the Arab world are overwhelmingly
         * Gregorian, written with Arabic month names and Arabic-Indic digits —
         * which is exactly what `gregorian` + the `arab` numbering system below
         * produces. A site that genuinely wants the Hijri calendar sets
         * 'islamic-umalqura' here; that is a per-site editorial decision, not a
         * default the Core should impose.
         *
         * DISPLAY accepts any ICU calendar keyword — gregorian, persian,
         * islamic-umalqura, islamic-civil, buddhist, hebrew, japanese — because
         * formatting is ICU's job either way.
         *
         * The admin's date PICKER is narrower, and knowingly so. It draws its grid
         * from a precomputed table addressed as (year * 12 + month), so it supports
         * calendars with twelve months of a fixed length per year: persian,
         * islamic-*, buddhist. For anything else — Hebrew, whose years have twelve
         * or thirteen months, or an era-relative calendar like japanese —
         * LocalizedDate::calendarTable() returns null and the field falls back to
         * Filament's stock Gregorian picker. Dates still DISPLAY in the configured
         * calendar everywhere; only the picker grid reverts.
         */
        'calendars' => [
            'fa' => 'persian',
            'en' => 'gregorian',
            'ar' => 'gregorian',
        ],

        /*
         * ICU numbering system per locale — which digit GLYPHS are used.
         * `arabext` is the Persian (Eastern Arabic-Indic) set ۰۱۲۳۴۵۶۷۸۹,
         * `arab` the Arabic-Indic set ٠١٢٣٤٥٦٧٨٩, `latn` the ASCII set.
         */
        'numbers' => [
            'fa' => 'arabext',
            'en' => 'latn',
            'ar' => 'arab',
        ],

        /*
         * Named ICU date patterns.
         *
         * Explicit patterns rather than ICU skeletons (`yMMMMd` and friends).
         * Skeletons resolve to whatever the installed ICU version considers the
         * locale's preferred form, which means the panel's date format would
         * change under an ICU upgrade and the tests asserting it would break for
         * no reason in the application. These are stable.
         *
         * Every separator here (`/`, `-`, `:`, space) is bidi-neutral, so one
         * pattern set renders correctly in all three locales. Avoid adding a
         * literal comma: `،` is right for fa/ar and wrong for en.
         *
         * Any call site may also pass a raw ICU pattern instead of a name.
         */
        'patterns' => [
            'date' => 'yyyy/MM/dd',
            'date_time' => 'yyyy/MM/dd HH:mm',
            'date_time_seconds' => 'yyyy/MM/dd HH:mm:ss',
            'long' => 'd MMMM yyyy',
            'long_time' => 'd MMMM yyyy - HH:mm',
            'weekday' => 'EEEE d MMMM yyyy',
            'month_year' => 'MMMM yyyy',
            // Chart axis labels. `yyyy` not `yy`: a two-digit Persian year reads
            // as «تیر ۰۵», which is not a year anyone recognises.
            'month_short' => 'MMM yyyy',
            'time' => 'HH:mm',
        ],

        /*
         * Delivery API date output.
         *
         * Every ISO-8601 date in a Delivery payload is accompanied by a
         * pre-rendered `*_display` string in the calendar of the locale the
         * request resolved to. The ISO value is never replaced — a consumer that
         * needs to sort, diff or re-format reads that one and ignores the other.
         *
         * This is the headless-frontend half of the feature. The frontend is "not
         * part of this repo, any technology" (steering/product.md), so it cannot
         * be assumed to own a Persian calendar: in JavaScript the correct answer
         * is Intl with `fa-IR-u-ca-persian`, which is both non-obvious and
         * routinely got wrong by reaching for a date library instead. The CMS
         * already knows the request locale and already owns ICU, so it formats
         * once, server-side, and every frontend prints a string that agrees with
         * what the editor saw in the panel.
         *
         * `pattern` names the format from `patterns` above — a display date is
         * prose, so the default is the long form («۵ مهر ۱۴۰۵») rather than the
         * numeric one. Both `meta.calendar` and `meta.timezone` are reported
         * alongside it so a consumer can tell what it is looking at.
         *
         * Deliberately NOT behind an on/off switch: the response SHAPE has to be
         * stable, because RULE #3 pins it in docs/openapi.json and a key that
         * comes and goes with an env var would make the committed spec wrong for
         * half the installations.
         */
        'api' => [
            'pattern' => 'long',
        ],

        /*
         * How many years of calendar the admin's date picker can reach.
         *
         * The picker is handed a precomputed table of month lengths rather than
         * calendar arithmetic (see App\Support\Dates\LocalizedDate::calendarTable),
         * so the span is finite and costs roughly 12 bytes per year of payload.
         *
         * The default reaches back far enough to date an archive — 120 Persian
         * years is about 1907 — because a news site importing historical material
         * is a normal case, and 30 years forward is well past any editorial
         * schedule. A date outside the span still displays (in Gregorian) and
         * cannot be corrupted; it simply cannot be picked from the grid.
         */
        'picker' => [
            'years_before' => 120,
            'years_after' => 30,
        ],
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
    | (Requirement 5.3 — the ai_translated stage of the lifecycle), through one of
    | the OpenAI-compatible providers listed below.
    |
    | Only DEFAULTS live here. The operational values — whether the feature is
    | enabled, WHICH PROVIDER to use, which model, and the per-provider API keys —
    | are edited by an administrator on the Settings page and stored in the
    | `settings` table, not in code or .env, so one Core can be copied per client
    | without a redeploy (Requirement 1.2). The keys in particular must never live
    | in a committed file. The defaults here apply only until an administrator
    | overrides them.
    |
    */

    'ai' => [
        /*
         * The provider used when the admin has not chosen one on the Settings
         * page. OpenRouter because that is what this feature shipped with: an
         * install upgrading to the multi-provider version must keep translating
         * through the same service, with the key it already has stored, without
         * anyone touching a setting.
         */
        'provider' => 'openrouter',

        /*
         * The translation providers an admin may choose between
         * (App\Enums\AiProvider). All three speak OpenAI's chat-completions
         * protocol with a Bearer key, which is why one client serves all of them —
         * see the enum's docblock.
         *
         * Endpoints are configuration rather than constants so a deployment can
         * point a provider at a regional mirror or a corporate proxy without
         * editing code. `default_model` is per provider because a model id is NOT
         * portable between them: the catalogues overlap but are not identical, and
         * a default that is valid on one service answers 404 on another.
         *
         * `attribution_headers` sends OpenRouter's documented HTTP-Referer/X-Title
         * pair. It is off for the others deliberately — a header a provider never
         * asked for discloses the deployment's URL and client name to a third
         * party for no benefit.
         */
        'providers' => [
            'openrouter' => [
                'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
                'default_model' => 'openai/gpt-4o-mini',
                'attribution_headers' => true,
                'docs_url' => 'https://openrouter.ai/docs/api-reference/overview',
            ],

            'gapgpt' => [
                'endpoint' => 'https://api.gapgpt.app/v1/chat/completions',
                'default_model' => 'openai/gpt-4o-mini',
                'attribution_headers' => false,
                'docs_url' => 'https://gapgpt.app/platform-v2/docs/quickstart',
            ],

            'chatqt' => [
                'endpoint' => 'https://api.chatqt.com/api/v1/chat/completions',
                // ChatQT's own quickstart uses openai/gpt-4.1 as its worked
                // example, so it is the safest default to ship for that service.
                'default_model' => 'openai/gpt-4.1',
                'attribution_headers' => false,
                'docs_url' => 'https://chatqt.com/api.html#quickstart',
            ],
        ],

        'translation' => [
            /*
             * Global fallback model, used only when the SELECTED provider's entry
             * above names none. The admin can override the model per install on
             * the Settings page.
             */
            'default_model' => 'openai/gpt-4o-mini',

            // Seconds to wait for the whole outbound call before failing
            // gracefully.
            'timeout' => 30,

            /*
             * Seconds to wait for the CONNECTION alone, separate from the total
             * budget above. Without it, an endpoint that accepts nothing at all
             * (DNS gone, provider hard down) consumed the full timeout before
             * reporting a failure it could have reported in a second — and a run
             * makes several requests, so the difference is minutes of a worker's
             * life spent proving the network is down.
             */
            'connect_timeout' => 10,

            /*
             * Attempts per request, counting the first. Retries exist for exactly
             * two answers — a 429 (rate limited) and a 5xx (the provider is having a
             * moment) — both of which say "ask again" rather than "you asked wrongly".
             * A 4xx other than 429 is never retried: a bad key or an unknown model
             * will be just as bad on the third attempt, and retrying only triples
             * the latency before the editor sees the real problem.
             */
            'max_attempts' => 3,

            /*
             * Ceiling, in seconds, on how long a single retry will wait — including
             * when the provider's own Retry-After header asks for longer. The header
             * is honoured up to this point and refused beyond it: a queued job that
             * sleeps for ten minutes on a third party's say-so is occupying a worker
             * the rest of the queue needs, and the queue's own retry is a better
             * place to wait that long.
             */
            'max_retry_delay' => 30,

            /*
             * Output ceiling per request. Present so a runaway response cannot bill
             * for tokens nobody asked for, and sized against the chunk bounds below
             * — see max_characters_per_request.
             */
            'max_tokens' => 4096,

            /*
             * Chunk bounds for one batched request.
             *
             * A body is translated as a batch of its prose leaves. Sent whole, a long
             * article exceeds the model's OUTPUT token limit, the JSON is truncated
             * mid-string, the decode fails and the editor is told only that the
             * request failed. Both bounds are applied, because either alone is
             * escapable: forty short captions and four enormous paragraphs are the
             * same segment count and nothing like the same number of tokens.
             *
             * `max_characters_per_request` and `max_tokens` are two halves of one
             * setting. Raising the character bound without raising the token ceiling
             * reintroduces exactly the truncation the chunking exists to prevent.
             *
             * A single segment longer than the character bound is never split — a
             * split segment would break the 1:1 contract that keeps the document's
             * structure intact — so it forms a chunk of its own.
             */
            'max_segments_per_request' => 40,
            'max_characters_per_request' => 4000,

            /*
             * Hard cap on requests for one record and locale, enforced BEFORE any
             * HTTP work (segment collection is local, so this costs nothing). A
             * document needing more than this is refused with an actionable message
             * rather than quietly spending for twenty minutes.
             *
             * At the defaults this allows roughly 480 prose leaves or 48,000
             * characters of body — a very long article — plus the single batched
             * request that carries the plain fields.
             */
            'max_requests_per_record' => 12,
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
    | Sitemaps
    |--------------------------------------------------------------------------
    |
    | Requirements 7.2, 7.4. Decision D-5.
    |
    | Rows loaded per batch while a sitemap is generated. The generator walks
    | every indexable type with chunkById() rather than get(), because a sitemap
    | is the one response whose cost grows with the WHOLE archive rather than
    | with a page of it — at starter-kit scale it does not matter and at tens of
    | thousands of records a single get() is what exhausts the request's memory
    | limit. Larger batches mean fewer round trips and more resident rows; this
    | default is sized so a batch of articles with their media and translation
    | states stays comfortably small.
    |
    */

    'sitemap' => [
        'chunk' => env('CMS_SITEMAP_CHUNK', 500),
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
    | SEO Authoring Analysis
    |--------------------------------------------------------------------------
    |
    | Thresholds for the focus-keyphrase checks (App\Services\Seo\SeoAnalyser),
    | Requirement 7.1. Configurable because they are editorial conventions rather
    | than facts: a news desk publishing 200-word wires and a site publishing
    | long-form guides should not be told the same number is "too short".
    |
    | The density band is wide on purpose. Substring matching undercounts inflected
    | Persian forms and the word count treats ZWNJ-joined compounds as two words,
    | so the computed density sits below the real one — a narrow band would nag
    | editors whose copy is fine. See the SeoAnalyser docblock for what this
    | analysis deliberately does NOT measure.
    |
    */

    'seo' => [
        'analysis' => [
            'min_words' => env('CMS_SEO_MIN_WORDS', 300),
            'opening_words' => 50,
            'density_min' => 0.5,
            'density_max' => 2.5,
            'max_section_words' => 300,
        ],
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
