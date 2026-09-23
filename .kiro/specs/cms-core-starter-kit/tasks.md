# Implementation Plan — CMS Core Starter Kit

Ordered so that a working MVP (data models + Management API + admin skeleton) lands first.
Search, Redirects, and the Sitemap suite are deliberately pushed to later batches.

**Legend:** 🔒 = must-verify-manually before production trust (security-sensitive).
Each batch is a stopping point — review before starting the next.

> ⚠️ Batches 2 onward depend on sign-off of decisions **D-1 … D-10** in `design.md` §12.
> D-2 and D-3 determine the migrations, so do not start Batch 2 until they are confirmed.

---

## Batch 1 — Foundation

- [ ] 1. Scaffold Laravel 13 + Filament 5 baseline
  - Create Laravel 13 project, PHP 8.4, configure MySQL (prod) / SQLite (local) connections
  - Install Filament 5, Sanctum, Pest, Pint, Larastan (level 6)
  - Add `config/cms.php` with module toggles, locales (fa/en/ar), slide cap, API-key flag
  - _Requirements: 1.1, 1.3, 2.1, 2.2_

- [ ] 2. Enforce the no-cloud-storage and local-media constraints
  - Configure `media-library.php` disk `public`; ensure `filesystems.php` has no S3 disk
  - Add `storage:link` health check that fails loudly if the symlink is missing
  - _Requirements: 2.3, 2.4, 2.5_

- [ ] 3. Write the architecture tests that enforce the nine hard rules
  - No S3 package in `composer.json`; no external font/CDN host in built admin assets
  - Trait-presence assertions for `HasFeaturedImage` and `IsAuditable`
  - Placeholder for the OpenAPI-sync test (activated in Batch 4)
  - _Requirements: 2.3, 3.2, 9.6, 10.5_

- [ ] 4. Build the enums and shared concerns
  - `ContentStatus`, `TranslationStatus`, `UserRole`, `RedirectType`, `MediaRole`
  - `HasFeaturedImage`, `HasTranslationStatus`, `HasSeoMeta`, `HasSlug`, `IsAuditable`
  - _Requirements: 3.2, 3.6, 5.1, 5.3, 9.1_

---

## Batch 2 — Data Layer  *(blocked on D-1, D-2, D-3, D-4)*

- [ ] 5. Migrations for the content core
  - `contents`, `categories`, `tags`, pivots (`category_content` with `is_primary`,
    `content_tag`), `primary_category_id` FK per D-2
  - Per-locale generated slug columns + unique indexes (MySQL), app-level rule for SQLite (D-1)
  - _Requirements: 2.1, 3.1, 7.1_

- [ ] 6. Migrations for the media library
  - `media_assets`, `content_media_asset` pivot with `role`, Spatie `media` table (D-3)
  - `galleries` + gallery-item ordering, cover distinct from items (D-4)
  - _Requirements: 2.4, 2.6, 2.7, 3.1_

- [ ] 7. Migrations for supporting modules
  - `slides`, `pages`, `menu_items`, `settings`, `contact_settings`, `contact_submissions`
  - `redirects`, `content_versions`, `translation_statuses`, `activity_log`
  - `changelogs`, `system_info`
  - _Requirements: 3.1, 3.7, 5.3, 7.5, 9.5, 10.1_

- [ ] 8. Eloquent models, relationships, casts, and policies
  - All models per design §2.2 with translatable field registration
  - One Policy per model implementing the D-10 ability matrix
  - Factories and a seeder producing realistic Persian sample content
  - _Requirements: 3.1, 5.1, 9.1_

---

## Batch 3 — Admin Skeleton

- [ ] 9. 🔒 Panel provider: Persian, RTL, branded theme, mandatory MFA
  - Filament native MFA with `isRequired: true`, app authenticator + recovery codes
  - Middleware forcing MFA enrolment before any panel page renders
  - _Requirements: 4.1, 9.3, 9.4_
  - **Verify manually:** seed a fresh admin, confirm no panel route is reachable pre-enrolment

- [ ] 10. Bundle Vazirmatn locally and build the theme
  - `.woff2` files in `resources/fonts/vazirmatn/`, `@font-face` in the admin theme CSS
  - RTL layout, brand tokens as CSS custom properties (no hardcoded client colours)
  - Run the no-external-CDN architecture test against the built assets
  - _Requirements: 4.2, 4.3_

- [ ] 11. RichEditor with custom blocks, storing TipTap JSON
  - `RichEditor::make()->json()`; `RichContentCustomBlock` classes for Callout, Hero, Quote,
    Gallery Embed
  - _Requirements: 4.4, 4.5_

- [ ] 12. Filament resources for the content modules
  - Content, Category, Tag, Gallery, MediaAsset, Slide, Page, MenuItem
  - Featured-image field wired through the pivot per D-3; Form Request-equivalent validation
    rejecting a missing featured image
  - Slide cap of 5 enforced at validation level
  - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5_

- [ ] 13. Settings singleton, Contact submissions, and status workflow
  - Settings page (site identity, social links, GA/GTM, verification codes, maintenance mode)
  - Contact submissions read-only table with export
  - `draft → review → published → archived` transitions + scheduled publishing
  - _Requirements: 3.1, 3.6_

- [ ] 14. 🔒 Audit logging with no opt-out
  - `IsAuditable` on every content model, before/after state captured
  - Gate-denial logging; audit-log viewer page restricted to Admin
  - _Requirements: 9.2, 9.5, 9.6_
  - **Verify manually:** confirm no config flag, env var, or trait property can disable logging

- [ ] 15. Content versions and restore
  - Snapshot on update, prune to last N, restore action in the panel
  - _Requirements: 3.7_

---

## Batch 4 — APIs and Documentation

- [ ] 16. 🔒 Route groups, guards, and rate limiting
  - Delivery group `/api/v1/` (public, read-only) and Management group `/api/v1/manage/`
    (Sanctum + `abilities:manage`)
  - Optional Delivery API-key enforcement, default off (D-9); independent throttles
  - _Requirements: 8.1, 8.2, 8.3, 8.5, 8.6, 8.7_
  - **Verify manually:** confirm no write verb is routable on the Delivery group and that a
    401 does not disclose resource existence

- [ ] 17. Delivery API endpoints and JsonResource classes
  - Content, category, tag, gallery, slide, page, menu, settings, contact submit
  - Locale param, filtering, pagination, `related` field, fallback markers in `meta`
  - _Requirements: 4.8, 5.5, 8.3_

- [ ] 18. Management API endpoints
  - Full CRUD, publish workflow, media upload, translation review, redirects, audit log read
  - _Requirements: 8.5_

- [ ] 19. Response caching with tag-based invalidation
  - Tagged cache on Delivery responses; bust on underlying content write
  - _Requirements: 8.4_

- [ ] 20. Scramble OpenAPI generation + the sync-enforcement test
  - Commit `openapi.json`; activate `OpenApiSpecIsInSyncTest`
  - Add the `PostToolUse` Kiro hook regenerating the spec on API file writes
  - _Requirements: 10.4, 10.5_

- [ ] 21. Draft preview via signed, time-limited URLs
  - _Requirements: 4.6, 4.7_

---

## Batch 5 — i18n Engine

- [ ] 22. Translation status lifecycle and outdated-flagging
  - `source_hash` computation, automatic `reviewed → outdated` transition on source change
  - Translation review UI in the panel with per-locale status badges
  - _Requirements: 5.3, 5.4_

- [ ] 23. Locale routing and fallback resolution
  - `/fa/`, `/en/`, `/ar/` prefixes; explicit `is_fallback` / `fallback_locale` in responses
  - _Requirements: 5.2, 5.5_

---

## Batch 6 — SEO, Sitemaps, Redirects

- [ ] 24. SEO meta assembly and JSON-LD builders
  - Per-locale meta/OG/robots; schema builders for Article/NewsArticle, Organization,
    LocalBusiness, BreadcrumbList, ImageObject, VideoObject, FAQPage
  - _Requirements: 7.1, 7.3_

- [ ] 25. hreflang and the sitemap suite
  - Reciprocal hreflang + `x-default`; `sitemap-{locale}.xml` index, Image and Video sitemaps
  - Translation-status eligibility filter per D-5
  - _Requirements: 5.6, 7.2, 7.4_

- [ ] 26. Video metadata extraction
  - ffprobe-based duration/thumbnail extraction with manual-entry fallback per D-6
  - _Requirements: 7.4_

- [ ] 27. Redirect engine
  - `HandleRedirects` middleware, cached lookup, loop guard
  - 301 auto-suggestion on published-slug change
  - _Requirements: 7.5_

- [ ] 28. Slideshow performance contract and branded 404
  - Preload hint, fixed dimensions, no autoplay video; 404 page via the Page module
  - _Requirements: 3.8, 7.6_

---

## Batch 7 — Search

- [ ] 29. Per-locale Meilisearch indexes
  - `searchableAs()` per locale; TipTap JSON → plain text extraction per D-7
  - Queued index updates on publish/update/unpublish; `collection` driver locally
  - _Requirements: 6.1, 6.2, 6.4_

- [ ] 30. Delivery `/search` endpoint
  - Locale-aware, paginated, 503 with structured error when the service is unreachable
  - _Requirements: 6.3, 6.5_

---

## Batch 8 — Release Machinery

- [ ] 31. Versioning and changelog system
  - `system_info` SemVer, `VersionWidget` in the panel footer, About page with recent entries
  - `php artisan cms:release {major|minor|patch}` writing both the DB row and `CHANGELOG.md`
  - _Requirements: 10.1, 10.2, 10.3_

- [ ] 32. Final rule audit
  - Run the full architecture suite; diff `CHANGELOG.md`, `system_info`, and `openapi.json`
    against what actually changed
  - Write `docs/deployment.md` (Nginx media serving, ffmpeg, Meilisearch, Redis, Horizon)
  - _Requirements: all nine hard rules_
