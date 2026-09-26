# CMS Core Starter Kit

A reusable Persian-first headless CMS core, built to be copied per client. Laravel 13 with
a Filament 5 admin panel, a read-only Delivery API for public sites and a Sanctum-guarded
Management API, three locales from day one, and SEO output that search engines can actually
use.

The Core ships no client-specific content or configuration. Branding, locales, modules and
API limits are all data or environment values, so a copy is customised without editing it.

**Status:** all 32 planned tasks complete — 171 tests passing, PHPStan level 6 clean,
Pint clean.

---

## What it does

**Content**
- Articles, pages, categories (nested, with a primary path for breadcrumbs), tags,
  galleries, slideshows, menus, contact submissions and site settings — each a module that
  can be switched off in `config/cms.php`
- A rich editor storing TipTap JSON, with custom blocks: Callout, Hero, Quote and Gallery
  Embed
- Draft → published workflow, version history with restore, and draft preview through
  signed, time-limited URLs
- A reusable media library: describe an image once and every use is correct, with queued
  thumbnail and WebP conversions

**Three locales (fa / en / ar)**
- Per-locale fields, slugs and URLs, with RTL handled properly for both Persian and Arabic
- Dates in the reader's own calendar: Jalali throughout the Persian admin panel — including
  a Persian date picker — and, for the frontend, every API date carried both as ISO-8601 and
  pre-rendered in the locale's calendar. Storage stays UTC Gregorian. Any ICU calendar is a
  config key; see [docs/dates.md](docs/dates.md)
- A translation lifecycle — `not_translated → ai_translated → reviewed → outdated` — with a
  review screen, and an `outdated` flag raised automatically when the source changes
- Only `reviewed` and `outdated` content is eligible for sitemaps, hreflang and search.
  Publishing machine-translated text to Search Console is a reputational risk, not a
  coverage win

**SEO**
- Per-locale meta, Open Graph and Twitter cards, canonical URLs, reciprocal hreflang with
  `x-default`. The OG title and description can be overridden per record, and fall back to
  the meta values when blank
- A Google-style search-result preview per locale, showing the title, URL and description
  exactly as resolved for the API — including which words a result would truncate
- A per-locale focus keyphrase with honest checks: is the phrase in the title, the
  description, the slug, the opening, a heading; is its density sane; is there enough text
  with enough structure. Deliberately no stemming, synonym matching or readability score —
  see `App\Services\Seo\SeoAnalyser` for what is and is not measured, and why claiming more
  for Persian would be a confident-looking wrong number
- JSON-LD: Article, Organization, LocalBusiness, BreadcrumbList, ImageObject, VideoObject
  and FAQPage. The article's type is editor-selectable (`Article` / `NewsArticle` /
  `BlogPosting`), so an evergreen guide is not published as news
- A sitemap index per locale, plus image and video sitemaps
- A redirect engine that auto-suggests a 301 when a published slug changes — on articles,
  pages, galleries and categories alike — collapses chains and refuses to loop; exposed over
  the Delivery API, because the frontend is the deployment that actually receives the stale
  traffic (`docs/redirects.md`)
- A `robots.txt` generated for this host, with the configured panel path and an absolute
  sitemap URL

**APIs**
- `/api/v1/…` — Delivery (public, cached, rate-limited) and Management (Sanctum,
  ability-scoped), as two separate route groups with separate guards
- OpenAPI spec committed at `docs/openapi.json`, with a test that fails when it drifts from
  the code
- `docs/redirects.md` states what a frontend must implement: honouring redirects,
  canonicalising the homepage, and the module-toggle 404s

**Admin panel**
- Persian, RTL, mandatory two-factor authentication
- Vazirmatn bundled locally as woff2 — no Google Fonts, no CDN
- Role-based access (Admin / Editor / Author / Viewer) with full audit logging, including
  denied attempts

## Deliberately not included

Adding any of these is a defect, not an enhancement, and tests assert their absence:

- **Cloud or object storage.** Media is local-disk only (RULE #9).
- **GraphQL.** REST only.
- **Automated backups.** An operational concern for the host, not the application.

## Requirements

| | |
|---|---|
| PHP | **8.4+** — a hard floor; several dependencies require it |
| Database | **MySQL 8+** in production, SQLite for local development |
| Node | 22+ (build only) |
| Extensions | `mbstring`, `intl`, `gd`, `exif`, `pdo_mysql`, `zip`, `bcmath`, `openssl`, `fileinfo` |

`gd` is not optional in practice: Media Library generates thumbnail and WebP conversions
with it. `intl` is not optional either, and is declared in `composer.json` for that reason:
ICU provides the Persian calendar, month names and digit shapes the panel and API render
dates with.

**Optional:** Redis (faster cache, and the only store supporting cache tags), Meilisearch
(typo tolerance and relevance ranking), ffmpeg (fills a video's duration and dimensions
automatically; the Docker image includes it).

Why MySQL rather than SQLite in production: per-locale slug uniqueness is enforced by
stored generated columns with unique indexes, which SQLite cannot express.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

`--seed` runs `DatabaseSeeder`, which creates Persian demo content and four accounts, one
per role. Each uses the password `password`, and the admin signs in as
`admin@example.test`. The panel is at `/admin`.

That seeder is **development only**: it builds demo content with model factories, and
factories need `fakerphp/faker`, a dev dependency. Production uses `InstallSeeder` instead;
see [Production install & first admin](#production-install--first-admin) below.

A queue worker is needed for image conversions and search indexing:

```bash
php artisan queue:work
```

## Production install & first admin

Production runs `php artisan db:seed --class=InstallSeeder --force`, which creates **no
users** — only the version row, the settings singletons and the branded 404 page. Demo
content and the `@example.test` accounts belong to `DatabaseSeeder` and never reach a live
site.

Create the first account, then promote it:

```bash
php artisan make:filament-user
php artisan tinker --execute="App\Models\User::where('email','you@example.com')->update(['role'=>'admin'])"
```

`make:filament-user` sets the least-privileged role on purpose, so a new account is a viewer
until the tinker command promotes it to admin.

Media must live on the persistent storage path `/var/www/html/storage/app/public`. Without
a volume mounted there every upload is lost on redeploy, silently, because the panel keeps
working with an empty library.

For the full container walk-through, see **[docs/coolify.md](docs/coolify.md)** — its
Step 5 documents this same first-admin flow.

## Deployment

**[docs/coolify.md](docs/coolify.md)** — Docker and Coolify. One container plus MySQL is a
complete deployment; ten environment variables, and everything else has a correct default.

**[docs/deployment.md](docs/deployment.md)** — traditional hosting: nginx, queues, the
scheduler, optional services, and the per-client handover checklist.

The `Dockerfile` builds a single self-sufficient image (nginx + PHP-FPM, able to run the
worker and scheduler as well). Two things to know before deploying it:

- it listens on **8080**, because it runs unprivileged;
- the media directory **must** be on a persistent volume, or every upload is lost on
  redeploy — silently, because the application keeps working with an empty library.

## Working on it

```bash
composer gates     # Pint, PHPStan, OpenAPI export, tests
php artisan test
./vendor/bin/pint
./vendor/bin/phpstan analyse
```

`npm run build` must run before the test suite: an architecture test scans the built assets
for external font and CDN hosts, and `public/build` is gitignored.

Two commands worth knowing:

```bash
php artisan cms:release minor --added="…"   # bumps the version, changelog table and CHANGELOG.md together
php artisan cms:audit-rules                 # audits the nine architecture rules against the RUNNING app
```

`cms:audit-rules` is not a duplicate of the test suite. The tests prove the *code* is
compliant against a fresh database; the audit inspects a *running deployment* — resolved
filesystem disks, the live panel's MFA setting, the real version row. Neither can see what
the other sees, and the audit has already caught a real defect the tests could not.

## How it is organised

```
app/
  Concerns/        shared model behaviour (featured image, slugs, SEO, auditing)
  Filament/        the admin panel: resources, pages, widgets, fields, custom blocks
  Http/            controllers (Delivery + Management), middleware, resources
  Services/        SEO, sitemaps, search, media, content
  Support/         stateless helpers (TipTap, script folding, dates/calendars)
docs/              deployment guides, blueprint, date system, committed OpenAPI spec
.kiro/             the spec this was built from: requirements, design, tasks
tests/
  Architecture/    the nine hard rules, enforced as tests
  Feature/         behaviour
```

### The nine rules

`.kiro/steering/architecture-rules.md` lists nine non-negotiable rules — local-only media,
mandatory 2FA, no external CDN, a changelog and SemVer per release, API documentation that
cannot drift, audit logging with no opt-out, and so on.

Each has a test that fails when the rule is broken, and every one of those gates was
verified to fail before being trusted. A gate nobody has watched fail is an assumption.

### Design decisions

`.kiro/specs/cms-core-starter-kit/design.md` §12 records ten places where the blueprint was
ambiguous or self-contradictory, with the option chosen, the options rejected, and why. Read
those before changing the data model — several exist because the obvious approach is wrong.

## License

MIT.
