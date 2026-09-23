---
inclusion: always
---

# Architecture Rules — Hard Constraints

Source: CMS Core Starter Kit Blueprint v8 (FINAL), Section 12.
These are **enforced features, not documentation**. A task is not complete until its
rule is demonstrably working in code. If a rule and a convenience conflict, the rule wins.

## The Nine Rules (verbatim from blueprint §12)

1. **Changelog** — `changelogs` table + `CHANGELOG.md`, updated on every release
2. **Versioning** — Semantic Versioning in `system_info`, shown in admin panel
3. **API Documentation Auto-Sync** — any API change (new field, new route, changed response) must update the OpenAPI/Swagger spec in the same commit; undocumented API changes are incomplete work
4. **Admin Font** — Vazirmatn, bundled locally, `@font-face`, no external CDN
5. **Admin Panel** — Filament, Persian, RTL, custom branded theme, mandatory 2FA
6. **Editor** — Filament RichEditor with Custom Blocks, JSON storage
7. **Featured Image** — shared trait on every content-bearing model
8. **Audit Logging** — every admin write action logged automatically, no opt-out
9. **Local Media Storage** — no S3/cloud object storage; all files on local disk

## Enforcement Checklist (how each rule is verified)

| # | Rule | Enforcement mechanism | Verification |
|---|---|---|---|
| 1 | Changelog | `Changelog` model + `changelogs` table; release command appends to `CHANGELOG.md` | Architecture test: `CHANGELOG.md` mtime/content changed when version bumped |
| 2 | Versioning | `system_info` singleton row; Filament footer/About widget renders it | Widget renders current SemVer + last 5 changelog entries |
| 3 | API doc sync | Scramble generates spec from routes; CI test fails if committed `openapi.json` differs from freshly generated | `php artisan test --filter=OpenApiSpecIsInSync` |
| 4 | Admin font | Vazirmatn `.woff2` in `resources/fonts/`, `@font-face` in theme CSS | Architecture test greps built admin CSS/JS for `fonts.googleapis`, `fonts.gstatic`, `cdn.` → must be absent |
| 5 | Admin panel | Panel provider: `->defaultThemeMode()`, Persian locale, RTL direction, `->multiFactorAuthentication(..., isRequired: true)` | Login as fresh admin → forced into MFA setup before reaching dashboard |
| 6 | Editor | `RichEditor::make()->json()` + registered `RichContentCustomBlock` classes | DB column holds TipTap JSON, not HTML |
| 7 | Featured image | `HasFeaturedImage` trait; `featured` single-file media collection; Form Request requires it | Trait present on News, Page, Gallery, Slide; save without featured image → validation error |
| 8 | Audit logging | `LogsActivity` on all content models; no `$enabled = false` escape hatch | Architecture test: every model in `App\Models\Content` namespace uses `LogsActivity` |
| 9 | Local media | `config/media-library.php` disk = `public`; `filesystems.php` has no S3 entry | Architecture test greps `composer.json` for `league/flysystem-aws-s3-*` → must be absent |

## Explicitly Out of Scope — Do Not Implement

Adding any of these is a defect, not a bonus:

- Any cloud/object storage integration (S3, MinIO, R2, Spaces, GCS)
- GraphQL API
- Automated backup system (deferred as a separate operational task)

## Standing Constraints

- **Database**: MySQL for production, SQLite for local dev only. No Postgres-specific syntax.
- **API**: REST only, versioned at `/api/v1/`. Two separate route + guard groups —
  Management API (authenticated, full CRUD) and Delivery API (public, read-only, cached).
- **No external network calls from the admin panel.** Fonts, icons, and assets are bundled.
  Analytics/GTM codes are stored as settings and only emitted by the *frontend*, never the panel.
- **This is a reusable Core product, not a single site.** Every module must be independently
  toggleable via config. Never hardcode a client name, domain, brand colour, or phone number.
- **i18n is structural.** All three locales (`fa`/`en`/`ar`) are wired from day one even though
  only Persian content ships at launch. Never add a field to a content model without deciding
  whether it is translatable.
