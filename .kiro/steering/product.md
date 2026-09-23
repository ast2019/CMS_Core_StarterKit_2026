---
inclusion: always
---

# Product

**CMS Core Starter Kit 2026** — a reusable, headless-first CMS core in Laravel, intended to be
copied into an independent repository per client site and then customised and deployed separately.

## What it is

A three-layer system:

1. **Backoffice** — Filament admin panel, Persian UI, RTL, Vazirmatn font, mandatory 2FA.
2. **Core** — content repository, i18n/translation engine, local media service, auth/RBAC,
   search, SEO/GEO engine, redirect engine, versioning + changelog + API-docs engine, audit log.
3. **Frontend** — not part of this repo. Any technology. Consumes the Delivery API.

## Who uses it

| Consumer | Surface | Access |
|---|---|---|
| Content team (fa) | Filament backoffice | Authenticated, 2FA-gated, role-scoped |
| Client frontend | Delivery API `/api/v1/*` | Public, read-only, cached |
| Integrations / scripts | Management API `/api/v1/manage/*` | Sanctum token, admin-scoped |

## Design priorities, in order

1. **Reusability over convenience.** If a choice makes this site easier but the next site
   harder, it is the wrong choice. No client-specific values in code.
2. **Self-containment.** No runtime dependency on any external SaaS. Media is local,
   search is self-hosted Meilisearch, fonts are bundled.
3. **Enforced discipline.** Changelog, versioning, and API-doc sync are features with tests,
   not conventions that erode.
4. **Translation-ready, Persian-first.** Ship Persian; activating English/Arabic later must be
   a content task, never a re-engineering task.

## Launch state vs. built state

Built now, used later — do not "simplify" these away because launch only needs Persian:

- Three-locale translatable schema and URL structure (`/fa/`, `/en/`, `/ar/`)
- Translation status lifecycle: `not_translated → ai_translated → reviewed → outdated`
- Reciprocal `hreflang` + `x-default` across all three locales
- Per-locale sitemaps, plus dedicated Image and Video sitemaps
