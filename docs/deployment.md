# Deployment

Per-site deployment for a copy of the CMS Core Starter Kit. The Core itself is
deliberately host-agnostic; everything here is a per-client operational decision.

> **Deploying with Docker or Coolify?** Read **[docs/coolify.md](coolify.md)** instead
> for the container path — the `Dockerfile` builds a single self-sufficient image, and
> the media volume needs particular care. This file still applies for the decisions that
> are not about hosting: queues, video, search, and the per-client checklist at the end.

## Required services

| Service | Required? | Notes |
|---|---|---|
| PHP 8.4+ | **Yes** | Hard floor. `spatie/laravel-activitylog` 5.x, `laravel-sitemap` 8.x and `schema-org` 5.x all require `^8.4`. |
| MySQL 8.0+ | **Yes** (production) | Needed for the per-locale slug uniqueness indexes (Decision D-1). SQLite is local-dev only. |
| Nginx (or equivalent) | **Yes** | Serves media directly from local disk. |
| Redis 7+ | Recommended | Cache tags and queues. Without it the app falls back to the `database` driver and still boots. |
| Meilisearch | Optional | Search only. Its absence returns a structured 503 from `/api/v1/search`; nothing else degrades. |
| ffmpeg / ffprobe | Optional | Only for locally uploaded video (Decision D-6). See below. |

### PHP extensions

`mbstring`, `intl`, `gd`, `exif`, `pdo_mysql`, `zip`, `bcmath`, `openssl`, `fileinfo`.

`gd` is not optional in practice: Media Library generates the thumbnail and WebP
conversions with it, so without it every image upload fails at conversion time.

## First deploy

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
php artisan key:generate

# REQUIRED. Media is served from the local public disk (RULE #9) and is unreachable
# without this symlink. The app's health check fails loudly if it is missing rather
# than serving broken image URLs.
php artisan storage:link

php artisan migrate --force
php artisan db:seed --class=InstallSeeder --force   # first deploy only

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Then sign in at `/admin` and complete MFA enrolment. **This is not optional** — RULE #5
makes multi-factor mandatory, and the panel diverts an unenrolled account to the setup
page before any other screen. Store the recovery codes somewhere the client can reach
them; without them a lost authenticator means a database edit to recover.

## Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name example.com;
    root /var/www/cms/public;

    index index.php;
    charset utf-8;

    # RULE #9 — media is served straight off local disk. No CDN and no object storage
    # is involved, so this location is the entire media delivery path.
    location /storage {
        alias /var/www/cms/storage/app/public;
        access_log off;

        # Conversions are content-addressed by Media Library, so a long max-age is safe:
        # an edited image gets a new path rather than overwriting the old one.
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # Locally bundled admin font (RULE #4). Same reasoning.
    location /fonts {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;

        # Editors upload video; the default 2M would reject most of it.
        client_max_body_size 64M;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

## Queues

Image conversions, search indexing, sitemap regeneration and AI translation are all
queued, so a worker is not optional in production — without one, uploaded images never
get their thumbnails, search results never update, and a translator who clicks
"Translate with AI" is told the work was queued but never receives the outcome
notification.

AI translation is the longest job in the system. Every field and every prose leaf is
batched, so a typical record costs **two** sequential provider calls at
`cms.ai.translation.timeout` seconds each (30 by default): one for the plain fields, one
for the body. A long body is split into bounded chunks, at most
`cms.ai.translation.max_requests_per_record` (12) of them, and a record needing more is
refused up front with an actionable message rather than translated half way.

The provider is chosen per install on the Settings page — OpenRouter, GapGPT or ChatQT,
all of which speak the same OpenAI-compatible protocol. Each one stores its own API key,
so a client can be configured once and switched later without re-entering credentials;
the endpoints and per-provider default models live in `cms.ai.providers`, which is where
to point a provider at a regional mirror or proxy. Nothing about the queue sizing above
changes with the provider.

The job derives its timeout from those same values — including the retry budget
(`max_attempts`, `max_retry_delay`) — so it is a generous **kill-switch sized to the
worst case the configuration permits**, not an expected duration. A worker started with
a shorter `--timeout` than the job asks for would kill translations mid-run, after their
calls were paid for, so leave the worker's timeout at the default or above the job's.

```bash
php artisan queue:work --tries=3 --max-time=3600
# or, with Redis:
php artisan horizon
```

## Scheduler

```cron
* * * * * cd /var/www/cms && php artisan schedule:run >> /dev/null 2>&1
```

This is **not optional if you use scheduled publishing.** What is registered (see
`bootstrap/app.php`):

| Task | When | Why |
|---|---|---|
| `cms:publish-due` | every minute | Refreshes the Delivery content and sitemap caches when a record's embargo elapses |
| `cms:heartbeat` | every minute | Records that cron and a queue worker are alive, so the panel can say when they are not |
| `cms:prune-trash` | daily, 03:10 | Permanently deletes records that have been in the trash past `CMS_TRASH_KEEP_DAYS` |
| `queue:prune-batches --hours=48` | daily | `job_batches` grows on every batch |
| `queue:prune-failed --hours=336` | weekly | `failed_jobs` grows on every failure |

`cms:publish-due` is the one that matters for correctness. Scheduling works at the
database level without it — a record with status *published* and a future
`publish_date` is excluded by the `live()` scope and included once the clock passes it,
with no status transition involved. But Delivery responses are cached and invalidated on
model **writes**, and at the instant an embargo elapses nothing is written: no save, no
event, no observer. Without this tick a scheduled article stays behind a cached payload
until its TTL expires, and the cached sitemap can stay stale considerably longer, so
"publish at 8am" is not a promise the system keeps.

It runs every minute because the panel lets an editor choose a publish time to the
minute. The task is three `COUNT`s — `contents` and `galleries` carry a
`(status, publish_date)` index, `pages` does not and is small enough not to care — and it
returns without touching the cache when nothing is due, which is almost always.

If you change the interval, raise `CMS_PUBLISH_LOOKBACK` with it: the lookback window
must exceed the interval, or a publish landing between two runs is never noticed.

There is no catch-up, because the check is a window rather than a stored watermark. An
embargo that elapses while the scheduler is **not** running — a deploy, a paused cron, a
killed container — is outside the window by the time it resumes, and that record waits out
the Delivery TTL. After a deployment, sweep it:

```bash
php artisan cms:publish-due --lookback=86400
```

What this does **not** fix: the sitemaps are regenerated per request and served with
`Cache-Control: public, max-age=3600`, so a crawler can hold a sitemap without the new URL
for up to an hour whatever the application cache does. Shorten that `max-age` in
`SitemapController` if an hour matters for a given site.

Two things are deliberately **not** scheduled. The audit log is never pruned — RULE #8
makes it append-only with no opt-out, and a retention policy is that opt-out. Content
versions need no task either: `cms.versions.keep` is enforced inside the write that
creates a version, not nightly.

### The trash, and emptying it

Articles, pages, galleries, categories, tags, media assets, menu items and slides are all
**soft-deleted**: deleting one hides it and nothing more. The panel's lists carry a *Deleted*
filter (excluded by default) from which a record can be restored or destroyed permanently —
permanent deletion is admin-only, because it destroys the audit subject along with the record.

`cms:prune-trash` destroys anything that has been in the trash longer than
`CMS_TRASH_KEEP_DAYS` (default 30). Without it, "delete" means "hide for ever": the tables grow
without bound and so does the media disk, while an editor believes they have cleaned up.

Two things it will **not** do, and both are deliberate:

- It refuses to destroy a record that something still depends on, even when that something is
  itself in the trash — the category a trashed article recorded as its primary one, or the asset
  a trashed article uses as its featured image. Destroying either would leave that article
  restorable but wrong, and nothing would report it. Those rows stay, and the command says how
  many it kept.
- It never prunes the **audit log**. RULE #8 makes it append-only, so the rows recording that a
  record was destroyed outlive the record.

Run it by hand to see what it would do:

```bash
php artisan cms:prune-trash --dry-run
php artisan cms:prune-trash --days=90     # override the retention window for one run
```

Media is the reason this matters operationally: force-deleting an asset removes the original
**and** its six conversions from disk, which a mass `DELETE` would not. That is why the command
walks records one at a time rather than issuing one statement.

### Checking that any of this is actually running

Everything above fails **silently**. A stopped cron does not error — scheduled articles
simply never appear, while the dashboard goes on naming a publish time. A stopped worker
does not error either; queued work accumulates in a table nobody looks at.

`cms:heartbeat` fixes the blind spot. It stamps two rows in `system_heartbeats` every
minute — one for the scheduler, one written by a job it dispatches through the queue — and
the dashboard's **System status** card (administrators only) reads them:

| Reading | Meaning | Fix |
|---|---|---|
| Both running | cron and a worker are alive | — |
| Scheduler running, queue stopped | cron is fine, no worker is consuming the queue | start `queue:work` |
| Scheduler stopped | cron is not running `schedule:run` for this app; the queue reading is reported as *unknown* because nothing was dispatched to test it | fix the crontab above |
| Cache store warning | the store has no tag support, so every publish flushes the whole cache | use Redis |

An editor with content queued also sees the scheduler warning on their own **Scheduled**
card, because that is where the promise they rely on is made.

Tolerance is `CMS_HEARTBEAT_STALE_AFTER` seconds (default 300 — five missed ticks), so a
deploy or a container restart does not raise a false alarm. A monitor that cries wolf for
one skipped minute is a monitor people learn to ignore.

Note for verifying a deployment: the heartbeat lives in a **table**, not the cache. With a
tagless cache store every publish runs `Cache::flush()`, which would erase a cached
heartbeat and report the worker dead because someone published an article.

## Frontend URL

Set `CMS_FRONTEND_URL` when the public site is a separate deployment, which is the
normal case for this headless Core.

Getting it wrong is quietly expensive: canonical URLs, hreflang annotations and every
sitemap entry are built from it, so leaving it unset on a split deployment submits a
sitemap full of API hostnames to Search Console and indexes the wrong host.

## Redirects are the frontend's job

The redirect table is stored and maintained here; **honouring it is the frontend's
responsibility**, because a visitor clicking a stale link hits the old URL on the frontend
and never passes through this host's middleware. `docs/redirects.md` is the contract — read
it before signing off a deployment, because nothing errors when this is skipped. The
symptom is simply that accepted 301s appear to do nothing.

## robots.txt and the two hosts

`/robots.txt` is served dynamically by this application, not from `public/`. It has to be:
the panel path is configurable (`CMS_PANEL_PATH`), and the `Sitemap:` directive needs an
absolute URL on **this** host, which is where the sitemap suite is generated and served.

It describes this host only — panel, API, previews and media disallowed, sitemap index
advertised. The public frontend is a separate deployment with its own `robots.txt`, which
should advertise the same sitemap URL:

```
Sitemap: https://api.example.com/sitemap.xml
```

Do not copy this host's `robots.txt` to the frontend. It disallows `/api/` and the panel
path, neither of which exists there, and it says nothing about the frontend's own routes.

## Contact form spam defences (two fields the frontend sends)

`POST /api/v1/contact` is the only public write, so it is the only endpoint that can be
flooded with content. It has three defences, and only the first is enforced entirely here:

1. **A rate limit** (`throttle:cms-contact`) — the actual ceiling, and the only one an
   attacker cannot simply avoid.
2. **A honeypot field** — a decoy input a human never sees and therefore never fills.
3. **A timing value** — when the form was presented, used to reject a submission that took
   no time at all.

A submission failing 2 or 3 is **stored and flagged**, never refused. The sender gets the
same `201` either way — telling them which check fired is how the next attempt avoids it —
and the panel hides flagged rows behind the inbox's spam filter, where an editor can clear
the flag if a real enquiry lands there. Nothing is ever silently discarded.

Deliberately **not** a CAPTCHA. A hosted CAPTCHA makes the contact form depend on a third
party being reachable, which for Iranian deployments is a real availability problem, and it
puts someone else's script on a frontend this repository does not control.

### What the frontend must render

Both field names are configurable, because a fixed name in an open-source kit is a fixed
target for scrapers. Defaults:

```
CMS_CONTACT_HONEYPOT_FIELD=cms_reference
CMS_CONTACT_TIMING_FIELD=form_presented_at
CMS_CONTACT_MIN_FILL_SECONDS=3
CMS_CONTACT_REQUIRE_TIMING=false
```

Hide the honeypot **visually**, with CSS, rather than using `type="hidden"`. The reason is
not that bots ignore hidden inputs — one that fills every `<input>` fills a hidden one too.
It is that hidden inputs conventionally carry state a form must round-trip unchanged (CSRF
tokens, ids), so the automation that bothers to distinguish at all tends to preserve them
verbatim and overwrite only the visible fields. A CSS-hidden text input reads as a visible
field to a parser and as nothing at all to a person.

It also has to be kept away from autofill and from screen readers, or the two groups it
catches are password-manager users and blind visitors:

```jsx
<div aria-hidden="true" style={{ position: 'absolute', left: '-9999px' }}>
  <label htmlFor="cms_reference">Leave this empty</label>
  <input id="cms_reference" name="cms_reference" type="text"
         tabIndex={-1} autoComplete="off" defaultValue="" />
</div>
```

The timing value is a **Unix timestamp in seconds**, written when the form mounts:

```jsx
const presentedAt = useRef(Math.floor(Date.now() / 1000));
// …then post it as form_presented_at
```

Seconds, not milliseconds. `Date.now()` unscaled reads as a timestamp tens of thousands of
years in the future, which the inspector treats as a wrong clock and ignores — so the check
silently stops working rather than breaking anything you would notice.

Neither value is authenticated; a determined client can forge both. They raise the cost of
bulk automation, and that is all they are for.

### Turning the timing check up

`CMS_CONTACT_REQUIRE_TIMING` is **off by default**, and should stay off until the frontend
demonstrably sends the field. On, a submission carrying no timing value at all is flagged —
which for a frontend that never sends one means the entire inbox lands in the spam list.

Order of operations: ship the frontend fields, watch the inbox for a few days with the spam
filter set to "all messages", confirm nothing legitimate is being flagged, then set it to
`true`. If real enquiries start appearing with reason `missing_timing`, that is the frontend
having stopped sending the field — a deployment bug, not spam.

## Publish webhooks (telling the frontend to rebuild)

A Next.js frontend caches rendered pages — that is the point of it — and without a
webhook the only options are a fixed revalidation timer (so a correction waits out an
interval unrelated to when anything changed) or rendering every request (throwing away
the caching). Both are visible to readers.

Off by default. **Both** variables are required: with an endpoint but no secret, nothing
is sent, because a silent downgrade to unsigned requests on a public endpoint that
triggers work is worse than a feature that is plainly switched off.

```bash
CMS_WEBHOOK_ENDPOINTS=https://www.example.com/api/revalidate
CMS_WEBHOOK_SECRET=<a long random string>
```

Comma-separate the endpoints to notify a preview deployment as well as production.

Fired when an article, page, gallery or category is saved, deleted or restored — and
when a **scheduled** publish goes live, which no model observer can see because an
elapsing embargo is not a write. That last one is dispatched by `cms:publish-due`, so the
scheduler above is what makes scheduled publishing reach the frontend at all.

The body names what changed and where it lives; it deliberately carries no content, so
the Delivery API stays the single source of truth for what a record looks like:

```json
{
  "event": "published",
  "resource": "news",
  "id": 42,
  "occurred_at": "2026-09-26T12:00:00+00:00",
  "urls": { "fa": "https://www.example.com/fa/news/…", "en": "…" }
}
```

A receiver must verify the signature. The HMAC covers `timestamp + "." + rawBody`, not
the body alone — otherwise a captured request could be replayed forever with a fresh
timestamp header:

```ts
// app/api/revalidate/route.ts
import { createHmac, timingSafeEqual } from 'node:crypto'
import { revalidatePath } from 'next/cache'

export async function POST(request: Request) {
  const raw = await request.text()
  const timestamp = request.headers.get('x-cms-timestamp') ?? ''
  const signature = request.headers.get('x-cms-signature') ?? ''

  const expected = 'sha256=' + createHmac('sha256', process.env.CMS_WEBHOOK_SECRET!)
    .update(`${timestamp}.${raw}`)
    .digest('hex')

  const a = Buffer.from(signature)
  const b = Buffer.from(expected)

  if (a.length !== b.length || !timingSafeEqual(a, b)) {
    return new Response('invalid signature', { status: 401 })
  }

  // Reject anything older than five minutes, so a captured request cannot be replayed.
  if (Math.abs(Date.now() / 1000 - Number(timestamp)) > 300) {
    return new Response('stale timestamp', { status: 401 })
  }

  const { urls } = JSON.parse(raw) as { urls: Record<string, string> }

  for (const url of Object.values(urls)) {
    revalidatePath(new URL(url).pathname)
  }

  return new Response(null, { status: 204 })
}
```

Answer **2xx** on success. A 4xx other than 429 is treated as a configuration error and
logged once without retrying — retrying a rejected signature only delays you noticing.
A 429 or 5xx is retried with spaced backoff (30s, 2m, 10m), sized for the usual cause: a
deployment in progress.

## Media, crawlers and `CMS_MEDIA_URL`

Every media URL this application emits — the image and video sitemaps, the JSON-LD
`image` and publisher `logo`, `og:image`, `twitter:image` and the `url` on every
MediaAsset in the API — comes from one place: the `public` disk's `url` in
`config/filesystems.php`.

**Unset (the default).** Media is served from this host under `/storage`, and
`robots.txt` deliberately leaves that path **crawlable**. It must: `Disallow` does not
demote an image to a lesser kind of result, it stops the crawler fetching the file, so
disallowing it silently breaks the `<image:loc>` entries in `sitemap-images.xml`, the
article JSON-LD image Google needs for rich results, the share cards, the video
thumbnails and the publisher logo. This works, and it is the safe default.

**Set (recommended for a Next.js frontend).** Point it at a path on the frontend that
rewrites back to this host:

```bash
CMS_MEDIA_URL=https://www.example.com/media
```

```js
// next.config.js — on the frontend
module.exports = {
  async rewrites() {
    return [{
      source: '/media/:path*',
      destination: 'https://api.example.com/storage/:path*',
    }]
  },
}
```

Three things this buys: images are same-host as the pages that embed them (which the
sitemaps protocol asks for and Bing enforces more strictly than Google), the frontend's
CDN edge caches them so this application stops serving image bandwidth, and `/storage/`
here goes back to being disallowed — `RobotsController` switches on this setting, so
robots.txt describes whichever configuration is actually active.

One caveat with `next/image`: it serves optimised images from `/_next/image?url=…`. That
is fine for `<img>` tags, but **do not put it in `og:image` or JSON-LD** — those need the
plain, stable URL the API gives you, because social scrapers do not follow a transform
endpoint. Add this host (or the `/media` path) to `images.remotePatterns` and let the API
URL stand as the canonical one.

## Video (Decision D-6)

Google's video sitemap format requires a thumbnail. A thumbnail cannot be derived from
an uploaded MP4 without ffmpeg, and the blueprint's toolset does not include it — so
ffprobe is treated as a **soft** dependency:

- **installed** → duration and dimensions are filled automatically on upload;
- **absent** → the panel requires a thumbnail to be uploaded by hand before a locally
  hosted video can be published.

Externally embedded video supplies its own thumbnail and needs neither.

```bash
apt-get install -y ffmpeg      # optional
# then, if it is not on PATH:
CMS_FFPROBE_PATH=/usr/bin/ffprobe
```

## Search

Meilisearch is self-hosted (blueprint §5): no external service dependency.

```bash
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=<master key>
```

One index per locale — `contents_fa`, `contents_en`, `contents_ar`. Build them with:

```bash
php artisan scout:import "App\Models\Content"
```

Leaving `SCOUT_DRIVER=collection` is a valid choice for a small site: search falls back
to database matching with no extra service to run.

## One-time backfills after upgrading

Some fixes correct how new data is written and cannot retroactively fix old rows. Run
these **once** on any deployment that predates them. Both are safe to re-run.

### Media file metadata (Requirement 7.6)

```bash
php artisan cms:backfill-media-metadata --dry-run   # report first
php artisan cms:backfill-media-metadata
```

`mime_type`, `size`, `width` and `height` are recorded on upload now, but assets
uploaded before that are still null, and three things degrade silently as a result:

- the Delivery API cannot tell a frontend what space to reserve, so the image lands as
  a layout shift;
- `SchemaBuilder::imageObject()` omits width/height, costing rich-result eligibility;
- `SocialTagBuilder::cardType()` falls back to `summary` instead of
  `summary_large_image`, so every share of an older article previews as a thumbnail.

The command walks the library in batches (`--chunk`, default 200), skips assets that
are already complete, and keeps going past an asset whose file has gone missing from
disk — reporting its id instead of aborting. An asset with no file, or an image whose
file is gone, is listed for manual attention; it is repaired as far as the media row
allows and stays listed on a re-run.

It writes quietly and does not touch `updated_at`: the files did not change, only our
record of them, so this must not appear as an editorial change on every asset at once.
The **run** is audited as a single `cms` activity row (`MediaAsset.metadata_backfilled`)
carrying the counts and the ids touched — see the command's docblock for how that
reading of RULE #8 was arrived at.

### Translation staleness hashes — nothing to run

Listed here because it is the deploy-day question this change would normally raise, and
the answer is **no action required**.

Translation staleness is detected by comparing a stored hash of the Persian source
against a freshly computed one (Requirement 5.4). The hash is now normalised
recursively, so an editor save that merely reorders a TipTap node's keys no longer
changes it — previously that reordering flipped every reviewed locale to `outdated`,
which is exactly the false alarm the hash exists to avoid.

Changing how a stored hash is computed normally means every pinned value mismatches on
the first save after deploy, flipping every reviewed locale in the database to
`outdated` at once: a review backlog full of work nobody needs to do, which teaches
translators to clear the flag without reading it. So the hash carries a **scheme tag**
(`v2:…`), and a mismatch whose scheme is not the current one is silently re-pinned
instead of raised.

That is safe because the staleness check runs on every save: a row that is still
`reviewed` is one whose hash matched at its last save, so recomputing it describes the
same content the reviewer signed off on. There is no migration, nothing to run, and
nothing to schedule — a record nobody saves keeps its old hash and is re-pinned the
moment anyone touches it. A reviewed row with **no** hash at all is still flagged
`outdated`, because it never had a verified baseline to re-pin.

Bump `HasTranslationStatus::HASH_SCHEME` whenever the hash's inputs or encoding change,
and this stays free next time.

## Releases

```bash
php artisan cms:release minor --added="…" --fixed="…"
php artisan scramble:export      # ALWAYS, not only when routes changed — see below
php artisan cms:audit-rules      # confirms the release landed everywhere
```

`cms:release` updates all three of `system_info`, the `changelogs` table and
`CHANGELOG.md`. Editing `CHANGELOG.md` by hand makes the three disagree, and the tests
assert they match.

`scramble:export` must follow **every** release, even one that changed no route. The
spec's `info.version` is stamped from `system_info` (RULE #2), so a release always
changes the document — and a spec that advertises the previous version tells integrators
they are reading older documentation than they are. `cms:audit-rules` reports exactly
this mismatch; it is how the drift was caught the first time.

## Verifying the nine rules

```bash
php artisan cms:audit-rules
```

Run this after a deploy, after a release, and before handing a customised copy back to a
client. It exits non-zero if any of the nine §12 rules is unsatisfied or anything
out-of-scope has appeared.

The test suite enforces the same rules, so why a command as well? Because the two see
different things, and one blind spot each:

- The **test suite** runs against a fresh, ephemeral database. It proves the *code* is
  compliant. It cannot see a running deployment's state — so it cannot tell that the
  published spec's version has fallen behind the deployed release, because in a test
  database the version is always the initial one.
- **`cms:audit-rules`** inspects the *running* application: the resolved filesystem
  disks, the live panel's MFA setting, the real `system_info` row. It catches drift a
  source scan cannot — a cloud disk re-added through an env var, MFA switched off in a
  per-site panel override, a font provider swapped in a subclass.

A client copy that has been customised for two years is exactly the case the test suite
cannot vouch for, and this command can.

## Per-client checklist

Everything below is data, not code — the Core ships no client-specific values
(Requirement 1.2).

- [ ] `APP_NAME`, `APP_URL`, `CMS_FRONTEND_URL`
- [ ] **Site name (per locale) and social links** — Settings → *General*. The name is
      published to the frontend, used as the Organization name in the JSON-LD, and shown
      in this panel's own header, so an admin can tell which client's backoffice they are
      in. `InstallSeeder` writes the placeholder «سایت نمونه»; a site that skips this
      launches calling itself that.
- [ ] **Analytics and verification codes** — Settings → *Analytics & verification*.
      Stored here and handed to the frontend to render; this panel never loads them
      (RULE #4 — the backoffice makes no external requests).
- [ ] **Contact address, phone, office hours, form labels and map coordinates** —
      Settings → *Contact*. Latitude and longitude are both-or-neither; with both, the
      LocalBusiness JSON-LD is emitted.
- [ ] `CMS_BRAND_PRIMARY` for the panel accent colour. Note that the logo and favicon are
      **not** configurable yet — the accent colour and the site name are the whole of the
      panel's branding today.
- [ ] Disable unused modules in `config/cms.php`
- [ ] **Designate a homepage.** Open the page that belongs at `/fa` and set its *Page
      role* to *Homepage*. Optional — a site that skips this behaves exactly as before,
      with `GET /api/v1/home-page` answering 404 and the frontend rendering its own root —
      but without it the homepage is not a CMS concept and a menu item can only reach it as
      a raw `/fa` string. Only **one** page can hold the role; designating a second is
      refused with a message naming the first, including when the first is in the trash.
- [ ] **Declare the site's menu locations** in `cms.menus.locations` if the frontend
      renders anything other than a header, footer and sidebar. The list validates
      `menu_key` on save and decides whether `GET /api/v1/menus/{key}` is a 404, so a
      location the frontend asks for and this list does not contain is now an error rather
      than an empty menu. Optionally add a label under `cms.menu.location.{key}` in
      `lang/fa`, `lang/en` and `lang/ar`; without one the panel shows the raw key.
- [ ] **Confirm the frontend honours redirects** — see `docs/redirects.md`
- [ ] One admin account per real person, each with its own MFA enrolment
- [ ] Remove the seeded demo accounts (`*@example.test`)

## Things this Core deliberately does not do

Adding any of these is a defect, not an enhancement:

- **Cloud/object storage.** RULE #9. An architecture test fails the build if an S3
  flysystem adapter appears in `composer.json`, and `CmsServiceProvider` prunes cloud
  disks from the resolved config at boot.
- **GraphQL.** Out of scope; an architecture test asserts no GraphQL route or package
  exists.
- **Automated backups.** Deferred as a separate operational task (blueprint's stated
  exclusion). Back up the database and `storage/app/public` with whatever the host
  already uses — note that media lives on local disk, so a database-only backup is not
  a complete one.
