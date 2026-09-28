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
        // The form builder, and GET/POST /api/v1/forms. Depends on `contact`: forms off
        // leaves the contact form and its inbox working; contact off turns both off. Read it
        // through App\Models\Form::builderEnabled(), which applies that dependency.
        'forms' => env('CMS_MODULE_FORMS', true),
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

        /*
         * Item 12 — what each asset type may hold, checked against the file's CONTENT (finfo),
         * not its extension, by every upload path: the library form, the replace-file action and
         * the inline upload in the article form. MediaAsset::mimeTypesFor() is the one reader.
         *
         * Deliberately absent:
         *  - image/svg+xml. An SVG is a document that can carry script, and the media disk is
         *    served from the same origin as the panel. Image conversions cannot rasterise it
         *    either, so it would reach the API with no variants.
         *  - image/avif and image/heic, which the image conversions cannot read on every
         *    install. Add them per project once the image driver is known to support them.
         *
         * Office formats are listed by their Open XML types. libmagic recognises a .docx/.xlsx only
         * when `[Content_Types].xml` is the first entry in the zip, which Word and Excel write but
         * some other producers do not; such a file is detected as application/zip and refused
         * (the error names the detected type) rather than letting every zip file through. Phone
         * videos detected as video/x-m4v or video/3gpp are likewise refused until added here.
         */
        'mime_types' => [
            'image' => [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
            ],
            'video' => [
                'video/mp4',
                'video/webm',
                'video/quicktime',
            ],
            'document' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.oasis.opendocument.text',
                'application/vnd.oasis.opendocument.spreadsheet',
                'application/vnd.oasis.opendocument.presentation',
                'text/plain',
                'text/csv',
            ],
        ],

        /*
         * The file NAME extensions each type may be stored under — the other half of the check.
         *
         * Uploads keep the name the client chose, and the disk is served from the panel's origin
         * with the MIME type the web server infers from that extension. Content detection alone
         * cannot stop `evil.html`: libmagic reports HTML only when it sees markup in the first
         * 4 KB, so plain text followed by a script is `text/plain`, which documents may be — and
         * would then be served as text/html. Binding the extension closes that.
         */
        'extensions' => [
            'image' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            'video' => ['mp4', 'webm', 'mov'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'csv'],
        ],
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
    | Contact form
    |--------------------------------------------------------------------------
    |
    | Item 16 — spam defences for the one public write this API accepts.
    |
    | Deliberately NOT reCAPTCHA or any hosted alternative. A CAPTCHA would make the
    | contact form depend on a third party being reachable, which for the Iranian
    | deployments this kit targets is a real availability problem rather than a
    | theoretical one, and it would put a script from that third party on a frontend
    | this repository does not control. Both checks below are local and cost nothing.
    |
    | What they are honestly worth: they stop automated submissions, not a determined
    | human. A bot that renders the page fills the honeypot; a bot that POSTs straight
    | at the endpoint sends no timing value. Neither signal is AUTHENTICATED — a client
    | could forge both — so they raise the cost of bulk abuse and nothing more. The rate
    | limiter (`throttle:cms-contact`) remains the actual ceiling.
    |
    | A submission failing either check is stored and flagged, never rejected. See the
    | migration that adds `is_spam` for why.
    |
    | Requirement 3.1.
    |
    */

    'contact' => [
        'spam' => [
            /*
             * Name of the decoy input the frontend renders and a human never fills.
             *
             * Configurable because a fixed name is a fixed target: once a name ships in
             * an open-source kit, it is in every scraper's skip-list. A deployment that
             * starts seeing spam through changes this and its frontend together.
             *
             * The default avoids the names browsers and password managers autofill
             * (`website`, `url`, `company`) — autofill does not care that the field is
             * visually hidden, and a filled honeypot on a real visitor's submission is
             * the one false positive this design cannot detect. See docs/deployment.md
             * for the markup that keeps assistive technology and autofill away from it.
             */
            'honeypot_field' => env('CMS_CONTACT_HONEYPOT_FIELD', 'cms_reference'),

            /*
             * Name of the field carrying when the form was presented, as a Unix
             * timestamp in SECONDS (the frontend writes it from JavaScript when the form
             * mounts).
             *
             * Seconds rather than milliseconds because the value is compared against a
             * threshold measured in seconds, and a frontend sending Date.now() unscaled
             * is a mistake that would otherwise read as a timestamp in the year 57000 —
             * i.e. "in the future", which is a case that has to be handled anyway.
             */
            'timing_field' => env('CMS_CONTACT_TIMING_FIELD', 'form_presented_at'),

            /*
             * Seconds a human needs, at minimum, between the form appearing and being
             * submitted. Anything faster was not typed.
             *
             * Three is low on purpose. The message field requires ten characters, so a
             * genuine submission is already several seconds of typing; the threshold only
             * has to catch a submission that took no time at all. Raising it towards the
             * time a real message takes would start rejecting people who prepared their
             * text elsewhere and pasted it.
             */
            'min_fill_seconds' => (int) env('CMS_CONTACT_MIN_FILL_SECONDS', 3),

            /*
             * Whether a submission with NO timing value at all is flagged.
             *
             * Default off, and this is the important default in this section. The
             * frontend is a separate deployment written by the client (steering:
             * "not part of this repo"), so turning this on in the Core would flag every
             * enquiry from every existing frontend the moment it upgraded — the whole
             * inbox into the spam list, with the reason recorded but nobody looking.
             *
             * Switch it on once the frontend demonstrably sends the field. Until then the
             * check still fires for a submission that DOES carry a timing value and
             * submits impossibly fast, which is the strictly-better-than-nothing half.
             */
            'require_timing' => (bool) env('CMS_CONTACT_REQUIRE_TIMING', false),
        ],
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
             * Seconds a CDN or browser may reuse a Delivery response WITHOUT asking
             * again (AddDeliveryCacheValidators).
             *
             * Deliberately much smaller than cache_ttl above, because the two are not
             * the same kind of number. `cache_ttl` is how long this application may
             * reuse its own computed payload, and the observers invalidate it the
             * instant an editor saves — so it is a ceiling that rarely applies. Once a
             * response is sitting in a CDN or a browser, nothing here can reach it, so
             * the same 300 would leave an editor's correction invisible for five
             * minutes despite the server having discarded its copy immediately.
             *
             * Past this window the response is not re-transferred: every response
             * carries an ETag, so revalidation is a 304 with no body.
             *
             * 0 means "always revalidate" — still cached, still an ETag, just never
             * reused without a conditional request. Raise it for a site whose content
             * rarely changes; lower it to 0 for a newsroom that wants corrections live
             * immediately and can afford a conditional request per view.
             */
            'http_max_age' => env('CMS_DELIVERY_HTTP_MAX_AGE', 60),

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

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | The scheduled tasks are registered in bootstrap/app.php; this is the one
    | value worth tuning per deployment.
    |
    | `publish_lookback` is how far back cms:publish-due looks for a record whose
    | scheduled publish time has just passed. It MUST be larger than the interval
    | the command runs at, or a publish landing between two runs is never noticed
    | and the article stays behind a cached response until its TTL expires. The
    | default has a 30-second margin over the one-minute schedule.
    |
    | Raising it is safe and cheap: the command only counts rows, and invalidating
    | a cache tag twice costs nothing. Lowering it below the interval is the one
    | mistake to avoid.
    |
    */

    'scheduling' => [
        'publish_lookback' => (int) env('CMS_PUBLISH_LOOKBACK', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | System status
    |--------------------------------------------------------------------------
    |
    | Item 18 — how long the dashboard waits before reporting a subsystem stopped.
    |
    | `cms:heartbeat` stamps the scheduler and the queue worker every minute (see
    | bootstrap/app.php), and App\Filament\Widgets\SystemStatusWidget reads those stamps.
    |
    | The tolerance is several missed ticks rather than one, because a single tick can be
    | missed for reasons that are not a fault — a deploy, a slow host, a container restart —
    | and a monitor that goes red for that is a monitor people learn to ignore. Five minutes
    | against a one-minute schedule means five consecutive failures before anything is
    | claimed, which is long enough to be believed and short enough to matter.
    |
    | Lower it for a deployment that genuinely needs to know within a minute; raise it for
    | one where restarts are frequent. The model enforces a 60-second floor, so a mis-set
    | value cannot make every reading "stopped".
    |
    */

    'system' => [
        'heartbeat' => [
            'stale_after' => (int) env('CMS_HEARTBEAT_STALE_AFTER', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trash retention
    |--------------------------------------------------------------------------
    |
    | Item 10 — how long a deleted record stays recoverable before `cms:prune-trash`
    | destroys it permanently.
    |
    | A trash with no retention is not a trash, it is a hidden archive: the tables grow for
    | ever and — for media assets — so does the disk, while an editor believes they have
    | cleaned up. A trash that empties immediately is not one either.
    |
    | Thirty days is chosen against how the mistake is actually discovered. Deleting the wrong
    | record is noticed either at once or when somebody follows a link that used to work, and
    | that second case is weeks rather than months. Past a month, a "restore" would put
    | content back into a site that has moved on.
    |
    | The command floors this at one day, because a zero would destroy a record in the same
    | run that deleted it — which is what somebody sets while testing and forgets to change
    | back.
    |
    | Note what it does NOT govern: the audit log, which RULE #8 makes append-only with no
    | retention policy at all. The rows recording that a record WAS destroyed outlive the
    | record, by design.
    |
    */

    'trash' => [
        'keep_days' => (int) env('CMS_TRASH_KEEP_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend webhooks
    |--------------------------------------------------------------------------
    |
    | Tells the frontend that a record changed, so it can rebuild the affected
    | pages instead of waiting out a timer. This is what makes a Next.js
    | deployment's cached pages correct promptly rather than eventually.
    |
    | OFF by default: with no endpoint and no secret configured, nothing is sent
    | and nothing is queued. Both are required — a deployment with an endpoint but
    | no secret sends NOTHING rather than sending unsigned requests, because the
    | receiver is a public endpoint that triggers work and a silent downgrade to no
    | authentication is worse than a feature that is plainly switched off.
    |
    | The payload names what changed and its public URL per locale; the frontend
    | re-fetches through the Delivery API. See docs/deployment.md for the receiver,
    | including how to verify the signature.
    |
    */

    'webhooks' => [
        // Comma-separated, so a deployment can notify a preview build as well as
        // production without this becoming an array in .env.
        'endpoints' => env('CMS_WEBHOOK_ENDPOINTS'),

        'secret' => env('CMS_WEBHOOK_SECRET'),

        'connect_timeout' => (int) env('CMS_WEBHOOK_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('CMS_WEBHOOK_TIMEOUT', 10),
    ],

];
