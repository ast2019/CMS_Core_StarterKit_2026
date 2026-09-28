# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries are written by `php artisan cms:release`, which also bumps the version in
`system_info` and inserts a row into the `changelogs` table (RULES #1 and #2).
Editing this file by hand will make the three sources disagree.

## [0.9.0] - 2026-09-28

### Added

- The Docker image runs the Laravel scheduler and a queue worker itself, as supervised S6 services; switch either off with CMS_RUN_SCHEDULER=false or CMS_RUN_QUEUE=false when it runs elsewhere.

### Changed

- Queue retry_after defaults to 3600 seconds (was 90), longer than the AI translation job may run, so a running translation is never handed to a second worker and paid for twice.
- The contact form's labels are edited only in its form schema: the Settings page's contact form labels list is removed, and form_labels in GET /api/v1/contact is now built from the contact form's schema.

### Deprecated

- form_labels in GET /api/v1/contact; read GET /api/v1/forms/contact instead.

## [0.8.0] - 2026-09-27

### Added

- Form builder: forms with a per-locale field schema (text, email, tel, textarea, select, checkbox) built in the panel under System > Forms.
- Delivery API: GET /api/v1/forms/{key} serves a form's schema in the request locale, and POST /api/v1/forms/{key}/submissions validates answers against it with the contact form's spam defences and shared rate limit.
- The submissions inbox shows which form each submission came from, filters by form, and lists answers under their labels.

### Changed

- Contact submissions now belong to a form and store their answers in a payload; existing submissions are attached to the built-in contact form, and POST /api/v1/contact is unchanged.

## [0.7.0] - 2026-09-27

### Added

- Editorial calendar of scheduled publishing (Content, Page, Gallery) in the panel's own calendar.
- Media library: where-is-this-used on each asset, an unattached filter, a replace-file action, and uploads checked against the asset type by content and extension.
- Releases and the changelog now reach deployed installs automatically: cms:sync-release runs at container start, imports CHANGELOG.md and never lowers the version.
- Social links on the Settings page show which network each URL belongs to.

### Changed

- The admin panel makes no external requests: avatars are drawn locally and provider documentation is shown as text rather than linked.
- The denial audit no longer records hidden-button checks made while a page renders.
- Changelog sections in the panel are named in the panel language.

### Fixed

- The site logo could be deleted, because its delete guard read the wrong setting.
- Stored social links showed as [object Object] in the Settings form.
- Intermittent test failures from random Persian test data.

## [0.6.0] - 2026-09-25

### Added

- WITH_FFMPEG build argument, off by default, keeping the image at 750 MB for sites that do not host video locally.

### Changed

- Deployment is now a single Dockerfile image: docker-compose.yaml is removed, Redis and Meilisearch are no longer implied, and the image carries its own PHP defaults so a deployment needs ten environment variables.

### Fixed

- Production installs could not be seeded at all: db:seed runs DatabaseSeeder, whose demo content needs fakerphp/faker, a dev dependency absent from a --no-dev image. Added InstallSeeder with only what a live site needs, and an architecture test so the install path cannot acquire a dev dependency again.
- A container with no APP_LOCALE ran this Persian-first CMS in English with RTL off, because config/app.php still carried Laravel's 'en' defaults. The test suite could not catch it: phpunit.xml sets APP_LOCALE=fa, so the real default was never exercised.
- A fresh install reported RULES #1 and #3 as violated, because system_info started at a hardcoded 0.1.0 while the changelog and published spec named the deployed release. The initial version is now read from CHANGELOG.md, and CHANGELOG.md is imported into the changelogs table on install so the panel shows real history.

## [0.5.0] - 2026-09-23

### Added

- Decision D-6 is now wired: App\\Listeners\\ExtractVideoMetadata fills a video's duration and dimensions from ffprobe on upload, queued, without overwriting values entered by hand. The extractor existed but nothing ever called it.
- CMS_MEDIA_STORAGE selects between the managed media volume and a fixed host path.

### Changed

- Container images are now built on serversideup/php (nginx + PHP-FPM under S6, unprivileged www-data), replacing a hand-written nginx.conf, php.ini, FPM pool and supervisord.conf. The web image listens on 8080 and is ~350 MB smaller.
- Sitemaps are served as text/xml rather than application/xml, so they are gzipped by the default compression config of any standard web server.

## [0.4.0] - 2026-09-23

### Added

- Container deployment: multi-stage Dockerfile (PHP 8.4, nginx + php-fpm under supervisor, ffmpeg for Decision D-6) and docker-compose.yaml for Coolify, with a named volume for media so RULE #9 survives redeploys.
- docs/coolify.md — Coolify setup, the APP_KEY warning, post-deploy commands and troubleshooting.

### Fixed

- The application did not trust reverse-proxy headers, so behind any proxy every visitor shared one rate-limit bucket and the audit log recorded the proxy's IP instead of the administrator's. Now configurable via TRUSTED_PROXIES.

## [0.3.1] - 2026-09-23

### Added

- tests/Architecture/MigrationsAreReversibleTest — fails when any migration inherits the empty Migration::down(), including future published package stubs.

### Fixed

- Two migrations published from package stubs (activity_log, media) had no down(), so migrate:rollback silently left their tables behind and the next migrate failed with "table already exists". Both now drop what they create.

## [0.3.0] - 2026-09-23

### Added

- GitHub Actions CI with a MySQL job that exercises the per-locale slug uniqueness indexes (Decision D-1), which SQLite cannot express.
- php artisan cms:audit-rules — audits the nine hard rules against the running application and exits non-zero on any violation.
- docs/deployment.md covering Nginx media serving, queues, the optional ffprobe dependency, Meilisearch and the per-client handover checklist.

### Fixed

- The published OpenAPI spec advertised a stale version. info.version is now stamped from system_info at generation time, so a release always reaches the API documentation (RULES #2, #3).

## [0.2.0] - 2026-09-23

### Added

- Reusable multilingual CMS core: Filament backoffice, Delivery and Management APIs, local-only media storage
- Nine blueprint rules enforced by architecture tests
- Per-locale search, SEO/sitemap suite and redirect engine
