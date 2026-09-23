# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries are written by `php artisan cms:release`, which also bumps the version in
`system_info` and inserts a row into the `changelogs` table (RULES #1 and #2).
Editing this file by hand will make the three sources disagree.

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
