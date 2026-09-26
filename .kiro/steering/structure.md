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
│   ├── Pages/                   # Dashboard, Settings singleton, AuditLog, TranslationReview
│   ├── Widgets/                 # dashboard cards + VersionWidget (RULE #2)
│   ├── Forms/Components/        # custom fields — LocalizedDateTimePicker (calendar-aware)
│   └── RichContent/Blocks/      # RULE #6 — Callout, Hero, Quote, GalleryEmbed
│
├── Support/                     # stateless helpers, no state and no DB
│   ├── TipTap.php  ScriptFolding.php  AuditRedaction.php
│   └── Dates/LocalizedDate.php  # ICU calendars/digits/timezone — see docs/dates.md
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
├── js/filament/                 # Alpine components for custom Filament fields.
│                                # Plain ESM, no imports, published by `filament:assets`
│                                # to public/js/cms/ — NOT bundled by Vite, because
│                                # Filament's x-load fetches them only where used.
└── lang/{fa,en,ar}/

config/
├── cms.php                      # module toggles, locales, dates/calendars, slide cap, API keys
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

## Dates

One rule: **storage is UTC Gregorian, display is the locale's calendar, and the conversion
happens in exactly one place** — `App\Support\Dates\LocalizedDate`, configured by
`cms.dates`. See `docs/dates.md`.

- Never format a date with `Carbon::format()`, `->dateTime()` or `diffForHumans()` in the
  panel, a Blade view or an API resource. Carbon has no Persian calendar, so those render
  Gregorian dates — sometimes with Persian month names, which is worse than English.
- Never sort, filter or group by a formatted date. `defaultSort('created_at')` and
  `whereBetween` always use the raw UTC column.
- Machine-readable dates stay ISO-8601: sitemap `lastmod`, Open Graph times, the
  Management API, `CHANGELOG.md`. A `*_display` string is added ALONGSIDE the ISO value in
  Delivery payloads, never instead of it.
- Month-boundary maths (charts, "this month" counters) goes through
  `LocalizedDate::recentMonths()`. A Persian month does not start on the 1st of a Gregorian
  one, and bucketing by the wrong calendar splits every month in two.

## Where NOT to put things

- No business logic in Filament resources — call a Service.
- No query logic in API resources — they format, nothing else.
- No `Storage::disk('s3')` anywhere. Ever. (RULE #9)
- No new top-level `app/` directory without a matching update to this file.
