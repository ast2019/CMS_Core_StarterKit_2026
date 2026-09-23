# Deployment

Per-site deployment for a copy of the CMS Core Starter Kit. The Core itself is
deliberately host-agnostic; everything here is a per-client operational decision.

> **Deploying with Docker or Coolify?** Read **[docs/coolify.md](coolify.md)** instead
> for the container path — `Dockerfile` and `docker-compose.yaml` cover the service
> topology, and the media volume needs particular care. This file still applies for the
> decisions that are not about hosting: queues, video, search, and the per-client
> checklist at the end.

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
php artisan db:seed --force        # first deploy only: creates the 404 page and settings

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

Image conversions, search indexing and sitemap regeneration are all queued, so a
worker is not optional in production — without one, uploaded images never get their
thumbnails and search results never update.

```bash
php artisan queue:work --tries=3 --max-time=3600
# or, with Redis:
php artisan horizon
```

## Scheduler

```cron
* * * * * cd /var/www/cms && php artisan schedule:run >> /dev/null 2>&1
```

## Frontend URL

Set `CMS_FRONTEND_URL` when the public site is a separate deployment, which is the
normal case for this headless Core.

Getting it wrong is quietly expensive: canonical URLs, hreflang annotations and every
sitemap entry are built from it, so leaving it unset on a split deployment submits a
sitemap full of API hostnames to Search Console and indexes the wrong host.

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
- [ ] Site name, logo, favicon and social links in **Settings**
- [ ] Analytics and Search Console verification codes in **Settings**
- [ ] Contact address, phone and map coordinates in **Contact**
- [ ] `CMS_BRAND_PRIMARY` for the panel accent colour
- [ ] Disable unused modules in `config/cms.php`
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
