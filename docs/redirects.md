# Redirects — and what the frontend has to do

Requirement 7.5. Read this before assuming redirects work.

## The problem this solves

The redirect engine has always been complete. An editor renames a published slug, the
panel offers a 301, the row is stored, chains are collapsed, cycles are refused and
query strings are preserved.

And until the Delivery endpoints below existed, **none of it reached a visitor.**

This Core is headless (blueprint §1). The public site is a separate frontend
deployment; this host serves the admin panel, the Delivery API and the sitemaps. A
visitor who clicks a stale link hits the old URL **on the frontend**, which never passes
through `App\Http\Middleware\HandleRedirects` — and that middleware explicitly excludes
`api/*`, so the frontend could not piggyback on it by proxying either.

The observable symptom: an editor renames a slug, accepts the 301, checks the live site,
and gets a 404. Nothing is broken and nothing logs an error. The table is correct and
nobody is reading it.

**So honouring redirects is the frontend's job, and these endpoints are how it does
that.** A redirect table nobody queries is the bug.

## What this host still handles

`HandleRedirects` remains, and it is not redundant — it covers traffic that genuinely
arrives here: bookmarks, an older single-host deployment, and crawlers that learned
these URLs before the frontend was split out. It excludes `api/*`, the panel, `/up`,
`/storage`, `/livewire`, `robots.txt` and the sitemap suite.

Both it and the API go through `App\Services\Content\RedirectResolver`, so the two hosts
cannot disagree about where a chain ends or which status code to use. If you are adding a
third consumer, use that service rather than reading the table.

## Endpoint 1 — resolve one path

```
GET /api/v1/redirects/resolve?from=/fa/news/old-slug
```

For a server-rendered or edge-middleware frontend: on a 404, ask once, then emit the
redirect yourself.

```json
{
  "data": {
    "from": "/fa/news/old-slug",
    "to": "/fa/news/new-slug",
    "status": 301,
    "hops": 2,
    "preserve_query": true
  }
}
```

- `status` is the HTTP status to emit (301 or 302), not an enum name.
- `to` is already **collapsed** to the end of the chain. `hops` says how many stored
  redirects were traversed — `1` means this row pointed straight at the destination.
  Emitting the intermediate hop instead would produce a 301 to a 301, which is the
  crawler-budget problem collapsing exists to avoid.
- `preserve_query` is `false` when the destination carries its own query string. Respect
  it: overwriting a deliberate `?sort=latest` with the visitor's `?sort=oldest` silently
  defeats the redirect.

Responses:

| Status | Meaning |
|---|---|
| `200` | A redirect applies. Emit it. |
| `404` | No redirect for this path — render your not-found page (fetch the branded one from `GET /api/v1/not-found-page`). Also returned for a path caught in a cycle, deliberately: handing back a looping rule is worse than a 404. |
| `422` | No `from` parameter. |

`from` may be a bare path, a path with a trailing slash, a full absolute URL, or carry a
query string or fragment — all four are normalised to the same lookup, because a frontend
will pass whatever it received.

This endpoint has its **own rate limit** (`CMS_REDIRECT_RATE_LIMIT`, default 600/min)
rather than sharing the 120/min read allowance. Your frontend calls it from one server IP
for the whole site's traffic, so the shared budget would be exhausted by a crawler
walking a handful of stale URLs — and the thing that would break is redirect handling.

## Endpoint 2 — export the whole table

```
GET /api/v1/redirects?page=1&per_page=100
```

For a statically-exported or build-time frontend that compiles redirects into its own
configuration — `next.config.js` `redirects()`, a Netlify `_redirects` file, an nginx
`map`. Such a site has no request-time hook to call `resolve` from at all.

Same object shape per row, already collapsed. Paginated (`per_page` default 100, max 500)
and ordered by insertion, so walking page by page is stable as new redirects appear.
Rows caught in a cycle are omitted rather than exported.

Cached and tagged `cms:redirect`, so accepting a 301 suggestion invalidates the export
promptly without discarding cached article listings.

### Choosing between them

Not either/or — they serve different architectures:

- SSR / edge middleware / ISR → **`resolve`**. Always current, no rebuild needed.
- Static export / no request-time hook → **`redirects`**, at build time. Note the
  consequence: a redirect created after a build does not take effect until the next
  build. Trigger a rebuild on publish if that matters.

## The homepage, while you are here

`GET /api/v1/home-page` returns the Page designated as the site's homepage, served at
`/{locale}` (not `/{locale}/{slug}`). `404` means none is designated — render your own
root, as before.

Every URL this Core generates for the homepage — canonical, sitemap, hreflang, menu
links, slide links — is `/{locale}`. But `GET /api/v1/pages/{slug}` **still resolves it**,
so that a frontend built before the homepage concept keeps working.

That leaves one thing you must do: `PageResource` carries `is_homepage`, and when it is
`true` you should redirect `/{locale}/{slug}` to `/{locale}` (or at minimum emit a
canonical pointing there). Serving the same content at two URLs is duplicate content, and
it is the kind that stays invisible until a crawler finds it.

## Other things a frontend must adopt

- **Menu locations are validated.** `GET /api/v1/menus/{key}` now answers `404` for a
  location this deployment does not declare in `cms.menus.locations`, and `200` with an
  empty list for a declared location that has no items. Previously any key returned
  `200` and an empty array, so a typo was indistinguishable from an empty menu.
- **`slides[].link` is resolved per locale.** Same field, same type, but a slide may now
  point at a CMS record, in which case the URL is built from that record's slug for the
  requested locale and is `null` when the target is deleted, unpublished, or in a disabled
  module. `slides[].link_target` names the record (`{"type": "page", "id": 12}`) so you can
  choose an internal transition over a full navigation.
- **Disabled modules answer 404.** `slides`, `settings`, `contact` and `not-found-page`
  now honour their module toggles like every other endpoint (Requirement 1.1). A
  submission to `POST /api/v1/contact` with the module off returns `404` rather than the
  `403` it used to.

## robots.txt

Served dynamically from this host at `/robots.txt` — it has to be, because the panel path
is configurable and the `Sitemap:` directive needs an absolute URL on this host.

It describes **this** host only: it keeps crawlers out of the panel, the API, previews and
media, and points at the sitemap index. Your frontend has its own `robots.txt`, which
should advertise the same sitemap URL (the sitemaps are generated from the content
database here, not by you).
