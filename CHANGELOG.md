# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries are written by `php artisan cms:release`, which also bumps the version in
`system_info` and inserts a row into the `changelogs` table (RULES #1 and #2).
Editing this file by hand will make the three sources disagree.

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
