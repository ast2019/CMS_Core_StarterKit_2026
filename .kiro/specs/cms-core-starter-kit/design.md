# Design — CMS Core Starter Kit

Formalisation of Blueprint v8 (FINAL). Where the blueprint was internally inconsistent, this
document records a **resolution** and marks it `[DECISION]`. All `[DECISION]` items are listed
together in §12 for sign-off — several change the data model, so they must be confirmed before
migrations are written.

---

## 1. Architecture

Three layers, one repository (frontend excluded):

```
Frontend (any tech, fa/en/ar)  ──Delivery API (public, cached)──┐
                                                                ▼
                                                              CORE
Backoffice (Filament, fa, RTL) ──Management API (Sanctum)─────▶ │
                                                                │
   Content Repository (MySQL) · i18n/Translation Engine
   Media Service (LOCAL disk) · Auth/RBAC/MFA · Search (Scout+Meilisearch)
   SEO/GEO Engine · Redirect Engine · Versioning/Changelog/API-Docs · Audit Log
```

**Boundary rule.** Filament talks to Services, not to the Management API over HTTP. The
Management API is a second consumer of the same Services, for integrations and scripts. This
avoids the panel paying an HTTP round-trip and keeps one authority for business rules.

---

## 2. Data Model

### 2.1 Translatable field strategy

`spatie/laravel-translatable` stores each translatable field as a JSON object keyed by locale:

```json
{"fa": "عنوان خبر", "en": "News title", "ar": null}
```

Consequence for uniqueness: **MySQL cannot enforce a unique index inside a JSON document
directly.** Per-locale slug uniqueness requires a stored generated column per locale:

```sql
ALTER TABLE contents
  ADD COLUMN slug_fa VARCHAR(255)
    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(slug, '$.fa'))) STORED,
  ADD UNIQUE INDEX contents_slug_fa_unique (slug_fa);
```

`[DECISION D-1]` Generated columns exist for all three locales on every slugged model. SQLite
does not support `STORED` generated columns identically, so local dev falls back to an
application-level `UniqueTranslatedSlug` validation rule; production gets both. Tests for slug
uniqueness run against MySQL in CI, not SQLite.

### 2.2 Models and field classification

| Model | Translatable | Non-translatable | Featured image |
|---|---|---|---|
| `Content` (News) | title, body, excerpt, slug, meta_title, meta_description, robots_meta | publish_date, scheduled_at, status, author_id, primary_category_id | **Required** |
| `Category` | name, slug, meta_* | parent_id, order | Optional |
| `Tag` | name, slug | — | — |
| `Gallery` | title, description, slug | — | **Required (cover)** |
| `MediaAsset` | alt_text, caption | file_path (local), mime, type, dimensions, duration | — |
| `Slide` | title | image, link, order, is_active | **Required** |
| `Page` | title, blocks, slug, meta_* | order, is_system (for 404) | **Required** |
| `ContactSetting` | form labels | address, phone, map_lat, map_lng | Optional |
| `ContactSubmission` | — | name, email, phone, message, ip, submitted_at | — |
| `MenuItem` | label | link, order, parent_id, menu_key | — |
| `Setting` (singleton) | site_name, social_link_labels | logo, favicon, ga_code, gtm_code, gsc_verification, bing_verification, maintenance_mode | — |
| `Redirect` | — | from_url, to_url, type, hits, created_at | — |
| `ContentVersion` | — | content_id, payload (JSON), user_id, created_at | — |
| `Changelog` | — | version, released_at, entries (JSON), type | — |
| `SystemInfo` (singleton) | — | version, installed_at, last_migrated_at | — |

### 2.3 Relationships

```
Content ──many-to-many──▶ Category      (pivot: category_content, is_primary bool)
Content ──many-to-many──▶ Tag           (pivot: content_tag)
Content ──many-to-many──▶ MediaAsset    (pivot: content_media_asset, role enum)
Content ──one-to-many───▶ ContentVersion
Gallery ──one-to-many───▶ MediaAsset    (pivot or FK — see D-3)
Category ─self-referencing parent_id
MenuItem ─self-referencing parent_id
MediaAsset ──morphMany──▶ media          (Spatie Media Library rows)
```

`[DECISION D-2] Category cardinality.` Blueprint §3 lists a singular `category_id` on News but
§9 declares `content ↔ category` many-to-many. These are mutually exclusive. **Resolution:**
many-to-many via `category_content`, plus a `primary_category_id` FK on `contents`. The primary
category drives the canonical URL and BreadcrumbList JSON-LD (which needs a single path);
secondary categories drive related-content suggestions and archive listings.

`[DECISION D-3] Media architecture.` Blueprint §9 specifies a `content_media` pivot with a
`role` field, but Spatie Media Library is polymorphic one-to-many — a `media` row belongs to
exactly one model and cannot be shared. Meanwhile §3 defines `MediaAsset` as a first-class
module, which implies a **reusable library** where one asset appears on many articles.
**Resolution — two-tier:**

- `MediaAsset` is the library entity. It owns its Spatie media (`original` + `conversions`
  collections) and holds the per-locale `alt_text`/`caption`.
- `content_media_asset` pivot attaches library assets to content with a `role` enum
  (`featured`, `inline`, `gallery`, `og_image`).
- `HasFeaturedImage` resolves the featured image *through* the pivot where `role = featured`,
  not via a direct Spatie collection on `Content`.

This preserves asset reuse (which a direct Spatie collection would forbid) while keeping
Spatie's conversion pipeline. It is more code than the naive approach and is the single most
consequential decision in this design.

`[DECISION D-4] Gallery cover vs. gallery items.` The `HasFeaturedImage` contract ("exactly
one") applies to the Gallery's **cover** only. Gallery items are a separate ordered collection
and are uncapped. The cover MAY also be one of the items.

### 2.4 Audit trail vs. content versions

These overlap and are deliberately kept separate:

| | `activity_log` (Spatie) | `content_versions` |
|---|---|---|
| Scope | Every write on every auditable model | `Content` and `Page` only |
| Purpose | Immutable forensic record — who did what | Restorable editorial snapshots |
| Mutability | Append-only, never edited or deleted | Prunable (keep last N) |
| Restore | Not supported | `restore(version)` action in panel |

---

## 3. i18n and Translation Engine

`HasTranslationStatus` maintains a `translation_statuses` table:

| Column | Purpose |
|---|---|
| `translatable_type`, `translatable_id` | polymorphic target |
| `locale` | fa / en / ar |
| `status` | enum: not_translated, ai_translated, reviewed, outdated |
| `source_hash` | hash of the source-locale translatable fields at time of review |
| `reviewed_by`, `reviewed_at` | audit |

**Outdated detection.** On save, the engine recomputes `source_hash` from the source locale's
translatable fields. For every other locale whose status is `reviewed`, if the stored hash no
longer matches, status flips to `outdated`. Hash-based rather than timestamp-based, so a
no-op save does not invalidate good translations.

**Fallback resolution.** A Delivery API request for a locale with no usable translation returns
source-locale content plus an explicit marker:

```json
{ "locale": "en", "fallback_locale": "fa", "is_fallback": true, "title": "عنوان خبر" }
```

The frontend can then choose to render, hide, or label it. Silent fallback is forbidden — it
produces duplicate-content SEO problems the client cannot diagnose.

`[DECISION D-5] Sitemap eligibility.` Blueprint §2 excludes untranslated content from a
locale's sitemap but does not say where `ai_translated` sits. **Resolution:** only `reviewed`
and `outdated` are sitemap-eligible. Unreviewed machine translation is excluded — indexing it
risks a quality penalty across the whole locale. Configurable via
`cms.sitemap.minimum_translation_status`.

---

## 4. Media Service (Local Disk Only)

- `config/media-library.php` → `disk_name = 'public'`. No S3 driver in `filesystems.php`.
- Conversions: `thumb` (400w), `medium` (1024w), `large` (1920w), each with a WebP sibling.
  Generated in a queued job; originals retained.
- Nginx serves `/storage/*` directly with long-lived cache headers. No CDN required.
- Video: local upload or external embed URL field.

`[DECISION D-6] Video metadata dependency.` The Video Sitemap requires duration and a thumbnail
for each video (Google's schema treats both as effectively mandatory). Extracting these from a
locally-uploaded file needs **ffmpeg/ffprobe on the host** — an infrastructure dependency absent
from blueprint §13. **Resolution:** `VideoMetadataExtractor` uses ffprobe when available;
when absent, the admin panel requires duration and thumbnail to be entered manually before the
video can be published. Deployment docs list ffmpeg as a soft requirement.

---

## 5. Search

One Meilisearch index per locale — Scout's default single-index model does not fit translatable
JSON, because a single index would mix languages and break relevance and stemming.

```php
public function searchableAs(): string
{
    return 'contents_'.app()->getLocale();   // contents_fa | contents_en | contents_ar
}
```

Indexing is driven by a queued observer, and writes to **all** locale indexes for which the
record has a usable translation. Searchable payload flattens title, body (plain text extracted
from TipTap JSON), excerpt, tag names, and category names for that locale.

`[DECISION D-7]` Body text is extracted from TipTap JSON to plain text before indexing — raw
JSON in the index would match on structural keys like `"paragraph"` and pollute relevance.

Local dev uses Scout's `collection` driver so Meilisearch is not required to run tests.

---

## 6. SEO / GEO Engine

| Concern | Implementation |
|---|---|
| Meta assembly | `HasSeoMeta` + `SeoService::for($model, $locale)`, falling back title → meta_title |
| hreflang | `HreflangBuilder` emits reciprocal links for every sitemap-eligible locale + `x-default` (= source locale) |
| JSON-LD | `spatie/schema-org` builders, one class per type under `Services/Seo/Schema/` |
| Sitemaps | `spatie/laravel-sitemap`; `SitemapGenerator` writes `sitemap-{locale}.xml` index + `sitemap-images.xml` + `sitemap-videos.xml`, regenerated by a scheduled job and on publish |
| GEO | Content model exposes `answer_paragraph` (self-contained first paragraph) and question-style heading extraction, per locale, surfaced in the Delivery API |

Slideshow performance contract: first slide image preloaded (`<link rel=preload>` hint provided
in the API payload), explicit `width`/`height` on every slide to prevent CLS, no autoplay video.

---

## 7. Redirect Engine

- `HandleRedirects` middleware runs early, matches `from_url` against the incoming path,
  increments `hits`, and issues a 301 or 302.
- Lookup is cached in full (the table is small); cache busts on any `Redirect` write.
- On slug change of a **published** record, a Filament notification offers one-click 301
  creation from the old path. Not automatic — bulk slug corrections during editing would
  otherwise generate redirect chains.
- `RedirectLoopGuard` rejects a redirect whose target resolves back to its source.

---

## 8. API Design

### 8.1 Surfaces

| | Delivery API | Management API |
|---|---|---|
| Prefix | `/api/v1/` | `/api/v1/manage/` |
| Guard | `delivery` | `sanctum` + `abilities:manage` |
| Operations | Read-only | Full CRUD, publish, upload, translation review, redirects, audit log |
| Caching | Response cache, tagged | None |
| Rate limit | `throttle:delivery` (default 120/min) | `throttle:manage` (default 60/min) |

`[DECISION D-8] Management API auth.` Blueprint §11 says "Session/JWT" but §13's toolset lists
Sanctum and no JWT package. **Resolution:** Sanctum only. Filament uses the stateful session
guard; programmatic Management API access uses Sanctum bearer tokens with an explicit `manage`
ability. JWT is dropped entirely — adding it would mean a third auth mechanism to secure.

`[DECISION D-9] Delivery API auth.` Blueprint §11 says "Public/scoped API key"; the build brief
says "public, read-only, cached". **Resolution:** the `delivery` guard is built with optional
API-key enforcement behind `cms.delivery_api.require_key`, defaulting to **false** (public) at
launch. The key infrastructure exists so a client can lock the API down without re-engineering,
which satisfies both statements. Note that enabling it makes responses per-key cacheable only —
document that trade-off.

### 8.2 Response envelope

All Delivery responses are `JsonResource` classes. Envelope carries locale/fallback state:

```json
{
  "data": { "...": "..." },
  "meta": { "locale": "fa", "is_fallback": false, "translation_status": "reviewed" },
  "links": { "self": "...", "next": null }
}
```

### 8.3 API doc auto-sync (Rule #3)

Scramble infers the spec from route signatures and `JsonResource` classes. Enforcement is a
test, not a habit:

```
tests/Architecture/OpenApiSpecIsInSyncTest.php
  → generate spec in memory
  → compare to committed openapi.json
  → fail with a diff if they differ
```

This is the rule most likely to be silently skipped, so it is wired as a hard CI gate. A
`PostToolUse` Kiro hook additionally regenerates the spec whenever a file under
`app/Http/Controllers/Api` or `app/Http/Resources` is written.

---

## 9. Security

- **RBAC:** four roles as a `UserRole` enum on `users`. One Policy per model, explicitly
  registered. Gate denials are logged (Requirement 9.2).
- **MFA:** Filament v5 native multi-factor authentication, `isRequired: true`, app-authenticator
  + recovery codes. A middleware on the panel forces enrolment before any page renders, so a
  newly seeded admin cannot bypass it.
- **Audit log:** `IsAuditable` wraps Spatie's `LogsActivity` with a fixed configuration and
  exposes no disable flag. An architecture test asserts every content model uses the trait.
- **Validation:** Form Requests only.
- **Guards:** separate, per §8.1.

`[DECISION D-10] Two-role matrix ambiguity.` The blueprint names Admin/Editor/Author/Viewer but
never defines their boundaries. **Proposed resolution:**

| Ability | Admin | Editor | Author | Viewer |
|---|---|---|---|---|
| View panel | ✓ | ✓ | ✓ | ✓ |
| Create/edit own content | ✓ | ✓ | ✓ | — |
| Edit others' content | ✓ | ✓ | — | — |
| Publish / unpublish | ✓ | ✓ | — | — |
| Delete content | ✓ | ✓ | — | — |
| Manage media library | ✓ | ✓ | own uploads | — |
| Review translations | ✓ | ✓ | — | — |
| Manage settings, menus, redirects | ✓ | — | — | — |
| Manage users | ✓ | — | — | — |
| View audit log | ✓ | — | — | — |

Needs confirmation — particularly whether Editor should manage redirects, since slug changes
generate them.

---

## 10. Versioning, Changelog, API Docs

- `SystemInfo` singleton holds the SemVer string; `VersionWidget` renders it in the panel
  footer, and an About page lists the five most recent `Changelog` entries.
- `php artisan cms:release {major|minor|patch}` bumps `system_info`, inserts a `Changelog` row,
  and prepends a Keep-a-Changelog section to `CHANGELOG.md`.
- OpenAPI sync per §8.3.

---

## 11. Testing Strategy

| Layer | Tool | Covers |
|---|---|---|
| Architecture | Pest arch tests | The nine hard rules — no-S3, no-CDN-fonts, trait presence, OpenAPI sync |
| Feature | Pest | Delivery + Management API, Filament resources, auth/MFA flows, redirects |
| Unit | Pest | Translation status transitions, hreflang builder, slug uniqueness, schema builders |

Slug-uniqueness and generated-column tests run against MySQL in CI (SQLite cannot represent them).

---

## 12. Open Decisions Requiring Sign-Off

| ID | Topic | Blueprint conflict | Proposed resolution | Impact if changed later |
|---|---|---|---|---|
| D-1 | Per-locale slug uniqueness | §3 calls slug both "non-localizable" and "(per locale)" | Translatable slug + MySQL generated columns + app-level rule | Migration rewrite |
| D-2 | Content↔Category cardinality | §3 says `category_id`; §9 says many-to-many | Many-to-many **plus** `primary_category_id` | Migration + API shape |
| D-3 | Media reuse vs. Spatie model | §9's `content_media` pivot is incompatible with Spatie's polymorphic media | Two-tier `MediaAsset` library + `content_media_asset` pivot with `role` | Large — core data model |
| D-4 | Gallery cover vs. items | "Required (cover)" vs. one-to-many items | Trait governs cover only | Small |
| D-5 | Sitemap vs. `ai_translated` | §2 silent on machine translations | Only `reviewed`/`outdated` are eligible | Config default |
| D-6 | Video sitemap metadata | ffmpeg absent from §13 toolset | ffprobe when present, else manual entry required | Deployment docs |
| D-7 | TipTap JSON indexing | Not addressed | Extract plain text before indexing | Small |
| D-8 | Management API auth | §11 "Session/JWT" vs. §13 Sanctum | Sanctum only, drop JWT | Medium |
| D-9 | Delivery API key | §11 "scoped API key" vs. brief's "public" | Optional key, default off | Small |
| D-10 | Role ability matrix | Roles named, never defined | Matrix in §9 | Policy rewrite |

Additionally noted, not blocking:

- **Slide cap.** §6 says "max 3–5 slides", the brief says "max 5". Taken as a hard cap of 5,
  configurable via `cms.slides.max`.
- **Page `blocks[]` vs. News `body`.** §3 implies different editors. Unified on RichEditor
  TipTap JSON for both; `Page.blocks` is a TipTap document, not a Filament Builder array.
- **Local dev weight.** Redis + Meilisearch + Horizon + MySQL is heavy for local work.
  Drivers degrade to `database`/`collection`/`sync` when `APP_ENV=local`.
