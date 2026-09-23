# Implementation Plan — CMS Core Starter Kit

Ordered so that a working MVP (data models + Management API + admin skeleton) lands first.
Search, Redirects, and the Sitemap suite are deliberately pushed to later batches.

**Legend:** 🔒 = must-verify-manually before production trust (security-sensitive).
Each batch is a stopping point — review before starting the next.

> ⚠️ Batches 2 onward depend on sign-off of decisions **D-1 … D-10** in `design.md` §12.
> D-2 and D-3 determine the migrations, so do not start Batch 2 until they are confirmed.

---

## Batch 1 — Foundation

- [x] 1. Scaffold Laravel 13 + Filament 5 baseline
  - Create Laravel 13 project, PHP 8.4, configure MySQL (prod) / SQLite (local) connections
  - Install Filament 5, Sanctum, Pest, Pint, Larastan (level 6)
  - Add `config/cms.php` with module toggles, locales (fa/en/ar), slide cap, API-key flag
  - _Requirements: 1.1, 1.3, 2.1, 2.2_

- [x] 2. Enforce the no-cloud-storage and local-media constraints
  - Configure `media-library.php` disk `public`; ensure `filesystems.php` has no S3 disk
  - Add `storage:link` health check that fails loudly if the symlink is missing
  - _Requirements: 2.3, 2.4, 2.5_

- [x] 3. Write the architecture tests that enforce the nine hard rules
  - No S3 package in `composer.json`; no external font/CDN host in built admin assets
  - Trait-presence assertions for `HasFeaturedImage` and `IsAuditable`
  - Placeholder for the OpenAPI-sync test (activated in Batch 4)
  - _Requirements: 2.3, 3.2, 9.6, 10.5_

- [x] 4. Build the enums and shared concerns
  - `ContentStatus`, `TranslationStatus`, `UserRole`, `RedirectType`, `MediaRole`
  - `HasFeaturedImage`, `HasTranslationStatus`, `HasSeoMeta`, `HasSlug`, `IsAuditable`
  - _Requirements: 3.2, 3.6, 5.1, 5.3, 9.1_

---

## Batch 2 — Data Layer  *(blocked on D-1, D-2, D-3, D-4)*

- [x] 5. Migrations for the content core
  - `contents`, `categories`, `tags`, pivots (`category_content` with `is_primary`,
    `content_tag`), `primary_category_id` FK per D-2
  - Per-locale generated slug columns + unique indexes (MySQL), app-level rule for SQLite (D-1)
  - _Requirements: 2.1, 3.1, 7.1_

- [x] 6. Migrations for the media library
  - `media_assets`, `content_media_asset` pivot with `role`, Spatie `media` table (D-3)
  - `galleries` + gallery-item ordering, cover distinct from items (D-4)
  - _Requirements: 2.4, 2.6, 2.7, 3.1_

- [x] 7. Migrations for supporting modules
  - `slides`, `pages`, `menu_items`, `settings`, `contact_settings`, `contact_submissions`
  - `redirects`, `content_versions`, `translation_statuses`, `activity_log`
  - `changelogs`, `system_info`
  - _Requirements: 3.1, 3.7, 5.3, 7.5, 9.5, 10.1_

- [x] 8. Eloquent models, relationships, casts, and policies
  - All models per design §2.2 with translatable field registration
  - One Policy per model implementing the D-10 ability matrix
  - Factories and a seeder producing realistic Persian sample content
  - _Requirements: 3.1, 5.1, 9.1_

---

## Batch 3 — Admin Skeleton

- [x] 9. 🔒 Panel provider: Persian, RTL, branded theme, mandatory MFA
  - Filament native MFA with `isRequired: true`, app authenticator + recovery codes
  - Middleware forcing MFA enrolment before any panel page renders
  - _Requirements: 4.1, 9.3, 9.4_
  - **Verify manually:** seed a fresh admin, confirm no panel route is reachable pre-enrolment

- [x] 10. Bundle Vazirmatn locally and build the theme
  - `.woff2` files in `resources/fonts/vazirmatn/`, `@font-face` in the admin theme CSS
  - RTL layout, brand tokens as CSS custom properties (no hardcoded client colours)
  - Run the no-external-CDN architecture test against the built assets
  - _Requirements: 4.2, 4.3_

- [x] 11. RichEditor with custom blocks, storing TipTap JSON
  - `RichEditor::make()->json()`; `RichContentCustomBlock` classes for Callout, Hero, Quote,
    Gallery Embed
  - _Requirements: 4.4, 4.5_

- [x] 12. Filament resources for the content modules
  - Content, Category, Tag, Gallery, MediaAsset, Slide, Page, MenuItem
  - Featured-image field wired through the pivot per D-3; Form Request-equivalent validation
    rejecting a missing featured image
  - Slide cap of 5 enforced at validation level
  - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5_

- [x] 13. Settings singleton, Contact submissions, and status workflow
  - Settings page (site identity, social links, GA/GTM, verification codes, maintenance mode)
  - Contact submissions read-only table with export
  - `draft → review → published → archived` transitions + scheduled publishing
  - _Requirements: 3.1, 3.6_

- [x] 14. 🔒 Audit logging with no opt-out
  - `IsAuditable` on every content model, before/after state captured
  - Gate-denial logging; audit-log viewer page restricted to Admin
  - _Requirements: 9.2, 9.5, 9.6_
  - **Verify manually:** confirm no config flag, env var, or trait property can disable logging

- [x] 15. Content versions and restore
  - Snapshot on update, prune to last N, restore action in the panel
  - _Requirements: 3.7_

---

## Batch 4 — APIs and Documentation

- [x] 16. 🔒 Route groups, guards, and rate limiting
  - Delivery group `/api/v1/` (public, read-only) and Management group `/api/v1/manage/`
    (Sanctum + `abilities:manage`)
  - Optional Delivery API-key enforcement, default off (D-9); independent throttles
  - _Requirements: 8.1, 8.2, 8.3, 8.5, 8.6, 8.7_
  - **Verify manually:** confirm no write verb is routable on the Delivery group and that a
    401 does not disclose resource existence

- [x] 17. Delivery API endpoints and JsonResource classes
  - Content, category, tag, gallery, slide, page, menu, settings, contact submit
  - Locale param, filtering, pagination, `related` field, fallback markers in `meta`
  - _Requirements: 4.8, 5.5, 8.3_

- [x] 18. Management API endpoints
  - Full CRUD, publish workflow, media upload, translation review, redirects, audit log read
  - _Requirements: 8.5_

- [x] 19. Response caching with tag-based invalidation
  - Tagged cache on Delivery responses; bust on underlying content write
  - _Requirements: 8.4_

- [x] 20. Scramble OpenAPI generation + the sync-enforcement test
  - Commit `openapi.json`; activate `OpenApiSpecIsInSyncTest`
  - Add the `PostToolUse` Kiro hook regenerating the spec on API file writes
  - _Requirements: 10.4, 10.5_

- [x] 21. Draft preview via signed, time-limited URLs
  - _Requirements: 4.6, 4.7_

---

## Batch 5 — i18n Engine

- [x] 22. Translation status lifecycle and outdated-flagging
  - `source_hash` computation, automatic `reviewed → outdated` transition on source change
  - Translation review UI in the panel with per-locale status badges
  - _Requirements: 5.3, 5.4_

- [x] 23. Locale routing and fallback resolution
  - `/fa/`, `/en/`, `/ar/` prefixes; explicit `is_fallback` / `fallback_locale` in responses
  - _Requirements: 5.2, 5.5_

---

## Batch 6 — SEO, Sitemaps, Redirects

- [x] 24. SEO meta assembly and JSON-LD builders
  - Per-locale meta/OG/robots; schema builders for Article/NewsArticle, Organization,
    LocalBusiness, BreadcrumbList, ImageObject, VideoObject, FAQPage
  - _Requirements: 7.1, 7.3_

- [x] 25. hreflang and the sitemap suite
  - Reciprocal hreflang + `x-default`; `sitemap-{locale}.xml` index, Image and Video sitemaps
  - Translation-status eligibility filter per D-5
  - _Requirements: 5.6, 7.2, 7.4_

- [x] 26. Video metadata extraction
  - ffprobe-based duration/thumbnail extraction with manual-entry fallback per D-6
  - _Requirements: 7.4_

- [x] 27. Redirect engine
  - `HandleRedirects` middleware, cached lookup, loop guard
  - 301 auto-suggestion on published-slug change
  - _Requirements: 7.5_

- [x] 28. Slideshow performance contract and branded 404
  - Preload hint, fixed dimensions, no autoplay video; 404 page via the Page module
  - _Requirements: 3.8, 7.6_

---

## Batch 7 — Search

- [x] 29. Per-locale Meilisearch indexes
  - `searchableAs()` per locale; TipTap JSON → plain text extraction per D-7
  - Queued index updates on publish/update/unpublish; `collection` driver locally
  - _Requirements: 6.1, 6.2, 6.4_

- [x] 30. Delivery `/search` endpoint
  - Locale-aware, paginated, 503 with structured error when the service is unreachable
  - _Requirements: 6.3, 6.5_

---

## Batch 8 — Release Machinery

- [x] 31. Versioning and changelog system
  - `system_info` SemVer, `VersionWidget` in the panel footer, About page with recent entries
  - `php artisan cms:release {major|minor|patch}` writing both the DB row and `CHANGELOG.md`
  - _Requirements: 10.1, 10.2, 10.3_

- [x] 32. Final rule audit
  - Run the full architecture suite; diff `CHANGELOG.md`, `system_info`, and `openapi.json`
    against what actually changed
  - Write `docs/deployment.md` (Nginx media serving, ffmpeg, Meilisearch, Redis, Horizon)
  - _Requirements: all nine hard rules_

---

## Implementation outcome

All 32 tasks complete. Gates at completion: **164 passed, 3 skipped** (the skips are the
MySQL-only Decision D-1 tests, which run in CI's MySQL job), PHPStan level 6 clean, Pint
clean across 241 files.

### Where the implementation departed from this plan

Recorded because a plan that is quietly edited to match what got built stops being a
review tool. Each of these was a design error found by building it.

**Media attachments are polymorphic, not Content-only (refines D-3).** The design named a
`content_media_asset` pivot. But RULE #7's featured image applies to Content, Page,
Gallery *and* Slide, so a Content-only pivot would have forced either four near-identical
pivot tables or a second mechanism for the other three. Implemented as one polymorphic
`media_attachments` table with an `attachable` morph and a `role` column. D-3's substance
— a reusable asset library separate from Spatie's one-owner media rows — is unchanged.

**No force-enrolment middleware (task 9).** The design specified middleware to divert
un-enrolled admins to MFA setup. Filament's native MFA challenges *before* authentication
completes, so a user is never inside the panel un-enrolled and the middleware could never
fire. It was dropped as dead code, not as a relaxation of RULE #5 — `cms:audit-rules`
reads `isMultiFactorAuthenticationRequired()` off the live panel.

**Accept-Language negotiation is opt-in (task 23).** Default `false`, behind
`cms.locales.negotiate_from_header`. Testing revealed Symfony's client sends `en-us` by
default, which exposed the real problem: header negotiation serves every English-preferring
browser a fallback-flagged Persian page and forces a `Vary: Accept-Language` that fragments
the Delivery cache. Explicit locale in the URL is the primary mechanism.

**Spec version is stamped from `system_info`, not config (task 32).** Found by
`cms:audit-rules` during the final audit: `config/scramble.php` held a static `0.1.0`
while the release was `0.2.0`, so the published API docs advertised the wrong release —
and `config/scramble.php`'s own comment claimed this could not happen. Now applied by a
Scramble document transformer at generation time, because config is resolved before the
database is available and `config:cache` would freeze a stale value.

This also changed the RULE #3 gate's shape. The sync test now excludes `info.version` from
its comparison: the test database is always a fresh install, so it reports the initial
version regardless of the release under test, and asserting on it would fail on every
release for a reason unrelated to whether the documented API *shape* matches the code. The
CI workflow's duplicate byte-exact `diff` step was removed for the same reason. Version
freshness is therefore checked by `cms:audit-rules` against a real database — the only
place that can know the answer.

### Division of labour between the two rule gates

Worth stating because it is the one thing about this codebase that is easy to get wrong
when extending it:

- The **test suite** proves the *code* is compliant, against an ephemeral database.
- **`cms:audit-rules`** proves the *running deployment* is compliant — resolved disks, the
  live panel's MFA flag, the real `system_info` row. It is what to run against a client
  copy that has been customised since handover.

Neither subsumes the other. Adding a rule check means deciding which of the two can
actually observe it.
