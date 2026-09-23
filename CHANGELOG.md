# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries are written by `php artisan cms:release`, which also bumps the version in
`system_info` and inserts a row into the `changelogs` table (RULES #1 and #2).
Editing this file by hand will make the three sources disagree.

## [0.2.0] - 2026-09-23

### Added

- Reusable multilingual CMS core: Filament backoffice, Delivery and Management APIs, local-only media storage
- Nine blueprint rules enforced by architecture tests
- Per-locale search, SEO/sitemap suite and redirect engine
