# Deploying on Coolify

This Core ships a Docker stack in `docker-compose.yaml`, built by `Dockerfile`. Coolify
reads both from the repository.

The images are built on [serversideup/php](https://serversideup.net/open-source/docker-php/),
which supplies nginx + PHP-FPM supervised by S6 Overlay, production defaults, and
configuration through environment variables. `docs/deployment.md` covers the
host-agnostic operational decisions and still applies.

## Three things that will bite you if skipped

**1. The port is 8080, not 80.** The web image runs unprivileged, so it cannot bind 80.
In Coolify the domain must be entered **with the port**:

```
https://cms.example.com:8080
```

The port tells Coolify's proxy where to forward inside the container; the public URL
still uses 443. A domain entered without it shows "No Available Server".

**2. The media volume is the whole ballgame.** Media lives on local disk by design
(RULE #9 — there is no S3 to fall back on, and the architecture tests fail the build if
a cloud adapter appears). Without a persistent volume, every image, video and document
the client ever uploaded is destroyed on the next redeploy.

It fails in the worst way available: nothing errors. The panel loads, uploads work, and
the library is simply empty — so the loss is usually noticed days later, by the client,
on the public site. See "Where the uploads actually live" below.

**3. `APP_KEY` is irreversible.** Copy it into the client's password manager after the
first deploy. It encrypts every administrator's two-factor secret; change or lose it and
everyone is locked out of the panel with no route back but a database edit.

## Where the uploads actually live

Inside every PHP container, uploads are at:

```
/var/www/html/storage/app/public
```

That is the path to mount. `public/storage` is a symlink into it, which is how the files
become reachable over HTTP.

You have two ways to persist it.

### Option A — named volume (default, recommended)

Leave `CMS_MEDIA_STORAGE` unset. Compose declares a named volume `cms-media`, Coolify
shows it under **Persistent Storage**, and Docker keeps it at:

```
/var/lib/docker/volumes/<project>_cms-media/_data
```

Recommended because of **ownership**: the containers run as `www-data` (uid/gid **33**)
and cannot change permissions themselves. A freshly created named volume inherits the
ownership of the image directory it covers — which the Dockerfile sets to `www-data` — so
it is writable with no privileged startup step. Verified: a file written in one container
is still readable from a brand-new one, and the volume shows `www-data:www-data` inside.

### Option B — a fixed host path you choose

Set `CMS_MEDIA_STORAGE` to an absolute path, e.g. `/data/cms-core/media`. Compose then
bind-mounts it instead. Easier to back up with ordinary host tools, and the location is
predictable.

**You must create and chown it first.** A bind mount keeps the host's ownership, and a
new directory belongs to root, so the unprivileged container cannot write to it:

```bash
mkdir -p /data/cms-core/media
chown -R 33:33 /data/cms-core/media   # 33 = www-data inside the image
```

Skip the `chown` and uploads fail with a permission error that reads like an application
bug. This is not theoretical — it was reproduced both ways while building this:
root-owned, the container could not write at all; after `chown 33:33`, it could.

Either way: **back the media up together with the database.** A database-only backup
restores rows pointing at files that no longer exist.

## First deploy

1. **New Resource → Application → Git repository**, pointing at this repo.

2. Set **Build Pack** to **Docker Compose**. Coolify defaults to Nixpacks, which will not
   work here — the build has ordering requirements only the Dockerfile expresses.

3. Leave the Compose file location at `docker-compose.yaml`.

4. Assign your domain to the **`app`** service, **with `:8080`** as above. Do not assign a
   domain to any other service.

5. Open **Environment Variables**. Coolify will have created an entry for every `${...}`
   in the Compose file, with generated values already filled in for passwords. Set these
   by hand:

   | Variable | Notes |
   |---|---|
   | `APP_NAME` | Shown in the panel and in emails. |
   | `APP_URL` | Defaults to the generated domain. Set explicitly if you assigned your own — canonical URLs, hreflang, sitemap entries and signed preview links are all built from it. |
   | `CMS_FRONTEND_URL` | The public site's base URL when it is a separate deployment — the normal case for this headless Core. Empty falls back to `APP_URL`. |
   | `CMS_BRAND_PRIMARY` | Panel accent colour. |
   | `MAIL_*` | Defaults to the `log` driver, which writes mail to the container log instead of sending it. |
   | `CMS_MEDIA_STORAGE` | Only if you want Option B above. |

6. Deploy. The stack starts in order: MySQL and Redis become healthy, `migrate` applies
   the schema and exits, then `app`, `worker` and `scheduler` start.

7. **Seed the first-run data**, once only, from Coolify's terminal on `app`:

   ```bash
   php artisan db:seed --force
   ```

   This creates the branded 404 page and the settings records. Deliberately not
   automatic: running it on every deploy is how seeders start fighting real content.

8. Create your administrator, sign in at `/admin`, and complete MFA enrolment. **Not
   optional** — RULE #5 makes multi-factor mandatory and the panel challenges before
   authentication completes. Store the recovery codes where the client can reach them.

9. Build the search indexes:

   ```bash
   php artisan scout:import "App\Models\Content"
   ```

10. Confirm the deployment is sound:

    ```bash
    php artisan cms:audit-rules
    ```

    All nine rules should report PASS. This inspects the *running* application rather
    than the source, so it catches deployment problems the test suite cannot see.

## Search: implemented, and on by default

Search is fully built — Scout with one Meilisearch index per locale (`contents_fa`,
`contents_en`, `contents_ar`), a Delivery endpoint at `/api/v1/search`, and queued
index synchronisation. The `meilisearch` service in the stack is its production backend
and `SCOUT_DRIVER` defaults to `meilisearch`, so it works out of the box once you run
`scout:import`.

It is listed as "optional" only in the sense that a client who does not want search can
drop it. The three levels, in case that is ever useful:

| Configuration | Result |
|---|---|
| `SCOUT_DRIVER=meilisearch` + the container (**default**) | Full search: typo tolerance, relevance ranking, per-locale indexes |
| `SCOUT_DRIVER=collection`, container removed | Works, but degrades to database matching — no typo tolerance, no ranking, and it slows down as content grows |
| Meilisearch configured but unreachable | `/api/v1/search` returns a structured 503; nothing else degrades |

## What runs, and why each one is needed

| Service | Image target | Role | Required? |
|---|---|---|---|
| `app` | `web` | nginx + PHP-FPM — **assign the domain here, port 8080** | Yes |
| `migrate` | `cli` | Applies migrations, then exits | Yes |
| `worker` | `cli` | Queue worker | **Yes** |
| `scheduler` | `cli` | `schedule:work` | Yes |
| `mysql` | — | MySQL 8 | **Yes** — not interchangeable with SQLite |
| `redis` | — | Cache and queue | Yes |
| `meilisearch` | — | Search backend | Default on |

**The worker is not optional.** Image conversions, search indexing, sitemap regeneration
and video metadata extraction are all queued. Without it, uploads succeed and thumbnails
never appear — with no error anywhere.

**MySQL is not interchangeable with SQLite.** Per-locale slug uniqueness (Decision D-1)
is enforced by stored generated columns with unique indexes, which SQLite cannot express.

**`migrate` is a separate one-shot service** so exactly one process touches the schema.
Left to startup, three containers race and the loser reports a confusing partial failure.
The image's own `AUTORUN_LARAVEL_MIGRATION` is therefore set to `false` everywhere else.

## Two images, from one source tree

- **`web`** — nginx + PHP-FPM, port 8080.
- **`cli`** — no web server; runs the worker, scheduler and migrations.

Two rather than one because they are supervised differently. S6 starts nginx and FPM in
the web image, so using it for a queue worker would run a web server alongside the worker
and let the health check pass on a container whose worker had died.

The `web` image is assembled by copying `/var/www/html` wholesale out of `cli`, so the two
cannot contain different code — the usual cause of "the job worked yesterday" after a
partial deploy.

**`ffmpeg` is installed only in `cli`** (~250 MB), because the listener that calls
`ffprobe` is queued, so the process that shells out to it is always a worker. That keeps
the image serving requests noticeably smaller.

## Build ordering that is easy to get wrong

Both of these were found by the build failing, not by planning:

- **Assets need `vendor/`.** The Filament custom theme imports Filament's own stylesheet
  out of `vendor/`, and Tailwind scans `app/Filament` for class names. A Node-only asset
  stage cannot resolve the import.
- **Extensions must come before Composer.** `composer install` validates the platform
  requirements of every package in the lockfile, and this set needs `ext-intl` and
  `ext-exif`, which the base image does not ship. Installing them afterwards fails the
  install with a message recommending `--ignore-platform-req`, which is the wrong fix.

Also worth knowing: **the asset stage is not optional.** `public/build` is gitignored and
Filament's published CSS/JS come from the Composer script phase, so an image missing
either boots successfully and then serves an unstyled admin panel.

## Customising nginx

The base image already provides the storage `.php` deny (the classic media-directory
RCE), immutable asset caching, gzip, security headers and dotfile denial. Only genuine
additions live in `docker/nginx-cms.conf`.

One sharp edge: **nginx treats a repeated directive as a fatal duplicate, not an
override.** Adding a second `real_ip_header` or `gzip_types` in the same context fails the
configuration and the container serves nothing at all. So:

- changing one means *replacing* the base file — see `docker/nginx-remoteip.conf`, which
  swaps the image's Cloudflare-oriented real-IP config for one reading `X-Forwarded-For`;
- adding a compressible content type means choosing one the base image already lists,
  which is why `SitemapController` serves `text/xml`.

## Scaling and tuning

Everything is an environment variable; no image rebuild required.

| Variable | Default here | Notes |
|---|---|---|
| `PHP_FPM_PM_MAX_CHILDREN` | 12 | Budget roughly 64 MB per child under this extension set |
| `PHP_MEMORY_LIMIT` | 512M | Raised from 256M: Media Library decodes uploads into bitmaps |
| `PHP_UPLOAD_MAX_FILE_SIZE` | 64M | The panel's own media form also caps uploads at 10 MB |
| `NGINX_CLIENT_MAX_BODY_SIZE` | 68M | Must be ≥ `PHP_POST_MAX_SIZE`, or nginx rejects the body before PHP sees it |
| `PHP_OPCACHE_MAX_ACCELERATED_FILES` | 20000 | Filament exceeds the stock 10000, after which nothing more is cached |

To run more queue workers, raise the replica count on `worker`. Do **not** replicate
`scheduler` — two schedulers run every scheduled task twice.

## Troubleshooting

**Domain shows "No Available Server".** Almost always the missing `:8080` on the domain.
Otherwise check that `app` is healthy; its health check is `GET /up`.

**Panel loads unstyled.** `public/build` is missing — the asset stage did not run or its
output was not copied. Rebuild without cache.

**Media 404s.** Check the volume is mounted at `/var/www/html/storage/app/public` and that
`public/storage` symlinks to it. The image recreates the symlink at start
(`AUTORUN_LARAVEL_STORAGE_LINK`).

**Uploads fail with a permission error.** Bind-mount ownership — `chown -R 33:33` on the
host path. Named volumes do not have this problem.

**Login appears to succeed but returns to the form.** `PHP_SESSION_COOKIE_SECURE` is `1`
and the site is being served over plain HTTP, so the browser discards the session cookie.
Use HTTPS, or set it to `0` deliberately.

**A changed environment variable seems ignored.** Redeploy rather than restart: config is
cached at container start.

**The container refuses to start, complaining about `APP_KEY`.** It is doing its job.
If the value looks like the literal `base64:${SERVICE_REALBASE64_32_APP}`, Coolify did not
substitute it — set `APP_KEY` explicitly with `php artisan key:generate --show`.
