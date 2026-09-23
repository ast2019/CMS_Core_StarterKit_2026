---
inclusion: always
---

# Project Structure

Standard Laravel 13 skeleton with these additions. Place new code in the directory that matches
its role — do not create parallel structures.

```
app/
├── Concerns/                    # Shared model traits
│   ├── HasFeaturedImage.php     # RULE #7 — News, Page, Gallery, Slide
│   ├── HasTranslationStatus.php # not_translated → ai_translated → reviewed → outdated
│   ├── HasSeoMeta.php           # per-locale meta_title/description/robots/OG
│   ├── HasSlug.php              # per-locale slug + 301 auto-suggest on change
│   └── IsAuditable.php          # RULE #8 — wraps spatie LogsActivity config
│
├── Models/
│   ├── Content.php  Category.php  Tag.php  Gallery.php  MediaAsset.php
│   ├── Slide.php  Page.php  ContactSubmission.php  MenuItem.php
│   ├── Redirect.php  ContentVersion.php  Setting.php
│   ├── Changelog.php  SystemInfo.php     # RULES #1, #2
│   └── User.php
│
├── Enums/                       # ContentStatus, TranslationStatus, UserRole, RedirectType
│
├── Policies/                    # RBAC — one policy per model, registered explicitly
│
├── Http/
│   ├── Controllers/Api/V1/
│   │   ├── Delivery/            # public, read-only, cached
│   │   └── Management/          # Sanctum + admin-scoped
│   ├── Requests/                # Form Requests only — no inline validation
│   ├── Resources/V1/            # JsonResource classes — Scramble reads these (RULE #3)
│   └── Middleware/
│       └── HandleRedirects.php  # redirect engine
│
├── Filament/
│   ├── Resources/               # one dir per module
│   ├── Pages/                   # Settings singleton, About (version + changelog)
│   ├── Widgets/                 # VersionWidget (RULE #2)
│   └── RichContent/Blocks/      # RULE #6 — Callout, Hero, Quote, GalleryEmbed
│
├── Services/
│   ├── Seo/                     # SchemaOrg builders, hreflang, meta assembly
│   ├── Sitemap/                 # per-locale index + Image + Video sitemaps
│   ├── Search/                  # per-locale Meilisearch index mapping
│   ├── Translation/             # status lifecycle, outdated-flagging, fallback resolution
│   └── Media/                   # local-disk conversions, WebP variants
│
└── Console/Commands/
    └── ReleaseCommand.php       # bumps system_info + writes CHANGELOG.md (RULES #1, #2)

resources/
├── fonts/vazirmatn/             # RULE #4 — local .woff2 only
├── css/filament/admin/theme.css # @font-face + RTL + brand tokens
└── lang/{fa,en,ar}/

config/
├── cms.php                      # module toggles, locales, slide cap, API-key enforcement
└── media-library.php            # disk => 'public' (RULE #9)

tests/
├── Architecture/                # the rule-enforcement tests — see architecture-rules.md
├── Feature/Api/{Delivery,Management}/
└── Feature/Filament/
```

## Naming

- Models singular (`Content`), tables plural snake_case (`contents`), pivots alphabetical
  (`category_content`).
- Enums over string constants for any closed set of values.
- API routes kebab-case, plural: `/api/v1/news`, `/api/v1/contact-submissions`.
- Filament resources mirror model names: `ContentResource`, `SlideResource`.

## Module toggling

Every module reads its on/off state from `config/cms.php`. A disabled module registers no
routes, no Filament resource, and no sitemap entries. Enforce this at the service-provider
boundary, not with scattered `if` checks inside controllers.

## Where NOT to put things

- No business logic in Filament resources — call a Service.
- No query logic in API resources — they format, nothing else.
- No `Storage::disk('s3')` anywhere. Ever. (RULE #9)
- No new top-level `app/` directory without a matching update to this file.
