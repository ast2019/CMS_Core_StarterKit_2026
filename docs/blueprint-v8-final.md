# CMS Core Starter Kit — Blueprint (v8, FINAL)

> Source of record for `.kiro/specs/cms-core-starter-kit/`. Preserved verbatim.
> Where this document is internally inconsistent, `design.md` §12 records the resolution.

**Scope**: reusable Core Starter Kit only. Per-project deployment decisions are handled separately per site.

---

## 1. Architecture (3 Layers)

```
┌─────────────────────────────────────────────┐
│   FRONTEND (any tech, 3 languages: fa/en/ar) │
└──────────────────┬────────────────────────────┘
                    │  Delivery API (REST, /api/v1)
┌──────────────────▼────────────────────────────┐
│              CORE                             │
│  - Content Repository (MySQL)                 │
│  - i18n / Translation Engine                  │
│  - Media Service (LOCAL disk storage)         │
│  - Auth Service (RBAC / Sanctum / 2FA)         │
│  - Search Engine (Scout + Meilisearch)         │
│  - SEO/GEO Engine (meta, sitemaps, hreflang,  │
│    JSON-LD)                                   │
│  - Redirect Engine                            │
│  - Versioning, Changelog & API Docs Engine    │
│  - Audit Log Engine                           │
└──────────────────▲────────────────────────────┘
                    │  Management API
┌──────────────────┴────────────────────────────┐
│   BACKOFFICE (Filament, Persian RTL, Vazirmatn)│
└─────────────────────────────────────────────┘
```

Database: MySQL (production), SQLite (local dev only).
API: REST, versioned at `/api/v1/`.
**Media storage: LOCAL disk only** — no S3/MinIO/external object storage. Files live under Laravel's local public disk (`storage/app/public`, symlinked to `public/storage`), served directly by the web server. No internet dependency for media delivery.

---

## 2. i18n Strategy

Full translatable architecture built now (`spatie/laravel-translatable`, JSON per field), launched with Persian content only; English/Arabic activated later as a content task, not re-engineering. URL structure: `/fa/`, `/en/`, `/ar/`. Per-locale translation status lifecycle: `not_translated → ai_translated → reviewed → outdated`, with automatic outdated-flagging and source-language fallback on missing/failed translation (excluded from that locale's sitemap).

---

## 3. Core Content Modules

| Module | Localizable fields | Non-localizable fields | Featured Image |
|---|---|---|---|
| News/Content | title, body, excerpt, meta_title, meta_description, alt_text | publish_date, author, category_id | Required |
| Category | name | slug (per locale), parent_id, order | Optional |
| Tag | name | slug (per locale) | — |
| Gallery | title, description | items[] | Required (cover) |
| Media Asset | alt_text, caption | file_path (local disk), type, thumbnail | — |
| Slide | title | image, link, order | Required |
| Page (static) | title, blocks[] | order | Required |
| Contact | form labels | address, phone, map coords, submissions[] | Optional |
| Menu/Navigation | item labels | link, order | — |
| Settings (global, singleton) | site_name, social_links_labels | logo, favicon, GA/GTM code, Search Console verification, Bing verification, maintenance_mode | — |
| Redirect | — | from_url, to_url, type (301/302) | — |

**Featured Image rule**: shared `HasFeaturedImage` trait (Spatie Media Library configured with the **local disk driver**) applied to News, Page, Gallery, Slide.

---

## 4. Editorial Features

- **Content Editor**: Filament RichEditor (TipTap), Custom Blocks (Callout, Hero, Quote, Gallery Embed), stored as structured JSON
- **Draft Preview**: signed, time-limited preview URL for unpublished/draft content
- **Related Content**: auto-suggested related articles based on shared category/tags, exposed via Delivery API as a `related` field
- **Custom 404 Page**: dedicated, brandable error page, manageable from the Page module

---

## 5. Search

- Laravel Scout + Meilisearch (self-hosted, no external service dependency)
- Indexes: content title, body, excerpt, tags, category name (per active locale)
- Delivery API `/search` endpoint, locale-aware, paginated

---

## 6. SEO + GEO (Multilingual)

- Per-locale `meta_title`, `meta_description`, OG tags, `robots_meta`, editable `slug`
- Reciprocal `hreflang` across all 3 locales + `x-default`, via sitemap `xhtml:link` annotations
- Sitemap suite: XML sitemap index (`sitemap-fa.xml`, `sitemap-en.xml`, `sitemap-ar.xml`) + dedicated Image Sitemap + Video Sitemap
- Per-locale JSON-LD: Article/NewsArticle, Organization, LocalBusiness, BreadcrumbList, ImageObject/VideoObject, FAQPage
- GEO: self-contained first-paragraph answers, question-based headings, per locale
- Homepage slideshow: max 3–5 slides, preloaded first image (WebP/AVIF), fixed dimensions, no autoplay video

---

## 7. Redirect Management

- `redirects` table (`from_url`, `to_url`, `type`, `created_at`)
- Auto-prompt to create a 301 when a published content's slug changes
- Manual management for arbitrary URL redirects

---

## 8. Security

- RBAC: Gates & Policies (Admin, Editor, Author, Viewer)
- Mandatory 2FA for all admin panel accounts (Filament native)
- Audit Log: every create/update/delete/publish action recorded (`activity_log`: user, action, model, before/after, timestamp)
- HTTPS everywhere, strict Form Request validation, separate Management/Delivery API guards, rate limiting

---

## 9. Database Relationships (Conceptual)

- `content` ↔ `category`: many-to-many
- `content` ↔ `tag`: many-to-many
- `content` ↔ `media`: many-to-many via `content_media` pivot (`role` field)
- `gallery` ↔ `media`: one-to-many
- `content_versions`: content edit history/rollback
- `contact_submissions`: contact form messages
- `redirects`: URL redirect rules
- `activity_log`: audit trail
- `changelogs`: system release history
- `system_info`: current app version
- Status workflow: `draft → review → published → archived`, with scheduling

---

## 10. Media Handling (LOCAL STORAGE)

- All uploads stored on local disk via Laravel filesystem (`public` disk), no S3/MinIO/cloud object storage
- Auto-generated thumbnails + WebP variants, saved locally alongside originals
- Video: direct upload (stored locally) or external embed field
- Mandatory `alt_text`/`caption` per asset, per locale
- Web server (Nginx) serves media directly from local storage path; optional local Nginx-level caching, no external CDN dependency required

---

## 11. API Design

| API Type | Consumer | Operations | Auth |
|---|---|---|---|
| Management API | Backoffice only | Full CRUD, publish workflow, media upload, translation review, redirects, audit log | Session/JWT, admin-scoped, 2FA-gated |
| Delivery API | Frontend | Read-only, locale param, filtering, pagination, search, sitemap feeds, JSON-LD | Public/scoped API key |

---

## 12. System Rules for AI Code Generation

Hard system rules, not optional documentation — each must be an enforced, working feature:

1. **Changelog** — `changelogs` table + `CHANGELOG.md`, updated on every release
2. **Versioning** — Semantic Versioning in `system_info`, shown in admin panel
3. **API Documentation Auto-Sync** — any API change (new field, new route, changed response) must update the OpenAPI/Swagger spec in the same commit; undocumented API changes are incomplete work
4. **Admin Font** — Vazirmatn, bundled locally, `@font-face`, no external CDN
5. **Admin Panel** — Filament, Persian, RTL, custom branded theme, mandatory 2FA
6. **Editor** — Filament RichEditor with Custom Blocks, JSON storage
7. **Featured Image** — shared trait on every content-bearing model
8. **Audit Logging** — every admin write action logged automatically, no opt-out
9. **Local Media Storage** — no S3/cloud object storage; all files on local disk

---

## 13. Laravel Toolset

| Capability | Tool |
|---|---|
| API/SPA auth + 2FA | Sanctum + Filament 2FA |
| Media management | Spatie Media Library (local disk driver) |
| Translatable content | spatie/laravel-translatable |
| Rich content editor | Filament RichEditor (TipTap) |
| Full-text search | Laravel Scout + Meilisearch |
| API responses | API Resource classes |
| API documentation | scramble or l5-swagger (auto-generated OpenAPI spec) |
| RBAC | Gates & Policies |
| Audit logging | spatie/laravel-activitylog |
| Redirects | custom redirect middleware + admin CRUD |
| Caching | Redis + Cache Tags |
| Rate limiting | Built-in Throttle middleware |
| Background jobs | Queue + Horizon |
| Admin panel | Filament |
| SEO/Sitemap | spatie/laravel-sitemap, spatie/schema-org |

---

## Status: LOCKED — Ready for Build

Deliberately excluded: automated backup system (deferred as a separate operational task, not part of Core Starter Kit code).
