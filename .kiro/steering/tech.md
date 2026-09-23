---
inclusion: always
---

# Technology Stack

Versions verified against Packagist on 2026-09-23. Pin majors in `composer.json`; do not
silently upgrade a major version as part of an unrelated task.

## Runtime

| Component | Version | Note |
|---|---|---|
| PHP | `^8.4` | Required floor — `spatie/laravel-activitylog` 5.x, `laravel-sitemap` 8.x and `schema-org` 5.x all require `^8.4` |
| Laravel | `^13.0` | 13.33 current |
| MySQL | 8.0+ | Production. Needed for JSON functions + generated columns used by per-locale slug uniqueness |
| SQLite | 3.x | Local dev only |
| Redis | 7+ | Cache tags + queue. Degrades to `database` driver locally |
| Meilisearch | latest | Self-hosted. Not required for local dev (Scout `null`/`collection` driver) |
| Node | 22 LTS | Asset build only (Vite) |

## Packages

| Capability | Package | Version |
|---|---|---|
| Admin panel | `filament/filament` | `^5.0` (5.8.4) |
| API/SPA auth | `laravel/sanctum` | `^4.3` |
| 2FA | Filament **native** MFA | built-in to v4+ — do **not** add a third-party 2FA plugin |
| Media | `spatie/laravel-medialibrary` | `^11.23` — local `public` disk driver only |
| Translatable | `spatie/laravel-translatable` | `^6.14` |
| Rich editor | Filament `RichEditor` + `RichContentCustomBlock` | built-in to v4+ |
| Search | `laravel/scout` | `^11.8` |
| Audit log | `spatie/laravel-activitylog` | `^5.1` |
| Sitemaps | `spatie/laravel-sitemap` | `^8.2` |
| JSON-LD | `spatie/schema-org` | `^5.0` |
| API docs | `dedoc/scramble` | `^0.13` |
| Queues | `laravel/horizon` | `^5.50` |

### Package decisions that are already settled

- **2FA**: Filament's built-in multi-factor authentication. `backstagephp/filament-2fa` is
  abandoned as of v4 precisely because this became native. Adding Breezy or similar is
  redundant surface area.
- **API docs**: Scramble over l5-swagger. Scramble infers the spec from type hints and
  `JsonResource` classes with no annotations, which is what makes rule #3 (auto-sync)
  mechanically enforceable in CI.
- **RBAC**: Laravel Gates & Policies. Do **not** add `spatie/laravel-permission` — four fixed
  roles (Admin/Editor/Author/Viewer) do not justify a dynamic permissions table.

## Commands

```bash
# Setup
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan storage:link          # REQUIRED — local media serving depends on this

# Dev
php artisan serve
npm run dev

# Build
npm run build

# Quality gates — all must pass before a task is considered done
composer test                     # Pest
composer lint                     # Pint
composer stan                     # PHPStan / Larastan
php artisan scramble:export       # regenerate openapi.json — commit the diff
```

## Conventions

- **Tests**: Pest. Feature tests over unit tests for anything crossing an HTTP boundary.
- **Validation**: Form Request classes only. Never validate inline in a controller.
- **API responses**: `JsonResource` classes only — Scramble reads them to build the spec, so
  returning a raw array or model silently breaks rule #3.
- **Static analysis**: Larastan level 6 minimum.
- **Formatting**: Laravel Pint, default preset.
- **Migrations**: one concern per migration; never edit a migration that has shipped.
- **Queues**: anything touching image conversion, translation, or sitemap generation is queued.
