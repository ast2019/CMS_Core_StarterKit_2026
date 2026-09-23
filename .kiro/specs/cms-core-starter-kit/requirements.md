# Requirements — CMS Core Starter Kit

Derived from Blueprint v8 (FINAL). Acceptance criteria are in EARS format.

## Requirement 1 — Reusable, Toggleable Core

**User story:** As an agency developer, I want a CMS core I can copy per client, so that each
site starts from a verified baseline instead of a fresh build.

1. WHERE a module is disabled in `config/cms.php`, THE SYSTEM SHALL register no routes, no
   Filament resource, and no sitemap entries for that module.
2. THE SYSTEM SHALL contain no client-specific name, domain, brand colour, phone number, or
   address in committed code; all such values SHALL be stored as Settings records.
3. WHEN the application runs with `APP_ENV=local` and no Redis or Meilisearch available,
   THE SYSTEM SHALL boot successfully using fallback cache, queue, and search drivers.

## Requirement 2 — Data Layer and Storage

**User story:** As a developer, I want a predictable persistence layer, so that content is
portable between environments.

1. WHERE `APP_ENV=production`, THE SYSTEM SHALL use MySQL 8.0+.
2. WHERE `APP_ENV=local`, THE SYSTEM SHALL support SQLite.
3. THE SYSTEM SHALL NOT include any cloud object storage driver, package, or configuration.
4. WHEN a file is uploaded, THE SYSTEM SHALL store it on Laravel's local `public` disk.
5. IF `php artisan storage:link` has not been run, THEN THE SYSTEM SHALL fail its health check
   with an actionable message rather than serving broken media URLs.
6. WHEN an image is uploaded, THE SYSTEM SHALL generate thumbnail and WebP variants stored
   locally alongside the original.
7. THE SYSTEM SHALL require `alt_text` per locale on every image asset before it can be
   attached to published content.

## Requirement 3 — Content Modules

**User story:** As an editor, I want all site content types in one panel, so that I do not need
external tools.

1. THE SYSTEM SHALL provide modules for News/Content, Category, Tag, Gallery, Media Asset,
   Slide, Page, Contact, Menu/Navigation, Settings, and Redirect.
2. WHEN an editor saves a News, Page, Gallery, or Slide record, THE SYSTEM SHALL require
   exactly one featured image.
3. IF a featured image is absent on save of a featured-image-bearing model, THEN THE SYSTEM
   SHALL reject the save with a per-field validation error.
4. THE SYSTEM SHALL enforce a maximum of 5 Slide records in the homepage slideshow.
5. IF an editor attempts to create a 6th active slide, THEN THE SYSTEM SHALL reject the
   create action with a validation error naming the cap.
6. THE SYSTEM SHALL move content through the states `draft → review → published → archived`
   and SHALL support scheduled publication at a future timestamp.
7. WHEN a content record is updated, THE SYSTEM SHALL persist a restorable `content_versions`
   snapshot.
8. THE SYSTEM SHALL expose a brandable 404 page managed through the Page module.

## Requirement 4 — Editorial Experience

**User story:** As a Persian-speaking editor, I want a native-feeling admin panel, so that I can
work without friction.

1. THE SYSTEM SHALL present the admin panel in Persian with RTL layout and a custom branded theme.
2. THE SYSTEM SHALL load the Vazirmatn font from locally bundled `.woff2` files via `@font-face`.
3. THE SYSTEM SHALL make no request to any external domain from the admin panel, including
   font, icon, and script CDNs.
4. THE SYSTEM SHALL provide a rich content editor storing structured JSON, not HTML.
5. THE SYSTEM SHALL provide Callout, Hero, Quote, and Gallery Embed custom blocks in the editor.
6. WHEN an editor requests a preview of unpublished content, THE SYSTEM SHALL return a signed,
   time-limited preview URL.
7. IF a preview URL's signature is invalid or its expiry has passed, THEN THE SYSTEM SHALL
   return HTTP 403.
8. THE SYSTEM SHALL auto-suggest related content based on shared categories and tags and
   expose it via the Delivery API as a `related` field.

## Requirement 5 — Internationalisation

**User story:** As a site owner, I want to add English and Arabic later, so that expansion is a
content task rather than a rebuild.

1. THE SYSTEM SHALL store translatable fields as JSON keyed by locale for `fa`, `en`, and `ar`.
2. THE SYSTEM SHALL serve locale-prefixed URLs `/fa/`, `/en/`, `/ar/`.
3. THE SYSTEM SHALL track per-locale translation status across `not_translated`,
   `ai_translated`, `reviewed`, and `outdated`.
4. WHEN a source-locale field changes on a record whose translation status is `reviewed`,
   THE SYSTEM SHALL set that locale's status to `outdated`.
5. IF a requested locale has no translation for a record, THEN THE SYSTEM SHALL return the
   source-locale content and indicate the fallback in the API response.
6. WHERE a record's translation for a locale is not `reviewed` or `outdated`, THE SYSTEM SHALL
   exclude that record from that locale's sitemap.

## Requirement 6 — Search

**User story:** As a visitor, I want to search the site, so that I can find content quickly.

1. THE SYSTEM SHALL index content title, body, excerpt, tag names, and category names.
2. THE SYSTEM SHALL maintain a separate search index per locale.
3. WHEN a search request specifies a locale, THE SYSTEM SHALL query only that locale's index
   and SHALL return paginated results.
4. WHEN a content record is published, updated, or unpublished, THE SYSTEM SHALL update the
   affected indexes asynchronously via a queued job.
5. IF the search service is unreachable, THEN THE SYSTEM SHALL return HTTP 503 with a
   structured error rather than an unhandled exception.

## Requirement 7 — SEO and GEO

**User story:** As a site owner, I want machine-readable multilingual SEO, so that content
ranks and is citable by AI answer engines.

1. THE SYSTEM SHALL provide per-locale `meta_title`, `meta_description`, OG tags,
   `robots_meta`, and an editable slug for every public content type.
2. THE SYSTEM SHALL emit reciprocal `hreflang` annotations across all three locales plus
   `x-default`.
3. THE SYSTEM SHALL emit JSON-LD for Article/NewsArticle, Organization, LocalBusiness,
   BreadcrumbList, ImageObject, VideoObject, and FAQPage, per locale.
4. THE SYSTEM SHALL publish a sitemap index per locale (`sitemap-fa.xml`, `sitemap-en.xml`,
   `sitemap-ar.xml`) plus a dedicated Image Sitemap and Video Sitemap.
5. WHEN a published record's slug changes, THE SYSTEM SHALL offer to create a 301 redirect
   from the previous URL to the new one.
6. THE SYSTEM SHALL serve the homepage slideshow with a preloaded first image in WebP or AVIF,
   fixed dimensions to prevent layout shift, and no autoplay video.

## Requirement 8 — API

**User story:** As a frontend developer, I want a stable documented REST API, so that I can
build any frontend against it.

1. THE SYSTEM SHALL version all API routes under `/api/v1/`.
2. THE SYSTEM SHALL separate the Management API and Delivery API into distinct route groups
   with distinct auth guards.
3. THE SYSTEM SHALL restrict the Delivery API to read-only operations.
4. WHEN a Delivery API response is generated, THE SYSTEM SHALL cache it and SHALL invalidate
   the cache by tag when underlying content changes.
5. THE SYSTEM SHALL require a valid Sanctum token with an admin-scoped ability for every
   Management API request.
6. IF an unauthenticated request reaches a Management API route, THEN THE SYSTEM SHALL return
   HTTP 401 without leaking resource existence.
7. THE SYSTEM SHALL rate-limit both API groups with independently configurable limits.
8. THE SYSTEM SHALL NOT expose a GraphQL endpoint.

## Requirement 9 — Security

**User story:** As a site owner, I want administrative access to be hard to abuse, so that the
site cannot be defaced.

1. THE SYSTEM SHALL implement the roles Admin, Editor, Author, and Viewer via Gates and Policies.
2. WHEN a user without the required policy ability attempts an action, THE SYSTEM SHALL deny
   it and SHALL record the denial.
3. THE SYSTEM SHALL require multi-factor authentication for every admin panel account.
4. WHEN an admin account without MFA configured logs in, THE SYSTEM SHALL force MFA enrolment
   before granting access to any panel page.
5. THE SYSTEM SHALL log every create, update, delete, and publish action with actor, action,
   model, before/after state, and timestamp.
6. THE SYSTEM SHALL provide no configuration option that disables audit logging.
7. THE SYSTEM SHALL validate every write request through a Form Request class.

## Requirement 10 — Versioning, Changelog, and API Docs

**User story:** As a maintainer, I want release metadata to be self-maintaining, so that it
does not silently rot.

1. THE SYSTEM SHALL store the current Semantic Version in `system_info`.
2. THE SYSTEM SHALL display the current version and recent changelog entries in the admin panel.
3. WHEN a release is cut, THE SYSTEM SHALL insert a `changelogs` record and update `CHANGELOG.md`.
4. WHEN any API route, request shape, or response shape changes, THE SYSTEM SHALL update the
   committed OpenAPI specification in the same commit.
5. IF the committed OpenAPI specification differs from the specification generated from current
   code, THEN the test suite SHALL fail.
