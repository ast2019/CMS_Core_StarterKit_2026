# Deploying on Coolify

This Core ships a Docker stack in `docker-compose.yaml`, built by `Dockerfile`. Coolify
reads both from the repository.

`docs/deployment.md` covers the host-agnostic operational decisions (queues, video,
search, the per-client handover checklist) and still applies. This file is only about
getting the containers up.

## Read this part first

**The `cms-media` volume is the whole ballgame.** Media lives on local disk by design
(RULE #9 — there is no S3 to fall back on, and the architecture tests fail the build if
a cloud adapter appears). If that volume is missing or gets recreated, every image,
video and document the client ever uploaded is gone.

It fails in the worst possible way: nothing errors. The panel still loads, uploads still
work, and the media library is simply empty — so the loss is typically noticed days
later, by the client, on the public site.

The Compose file declares the volume, and Coolify shows it under **Persistent Storage**.
Verify it is there after the first deploy, and back it up together with the database. A
database-only backup restores rows that point at files which no longer exist.

## First deploy

1. **New Resource → Application → Git repository**, pointing at this repo.

2. Set **Build Pack** to **Docker Compose**. Coolify defaults to Nixpacks, which will
   not work here — the build has ordering requirements that only the Dockerfile
   expresses (see "Why the build is arranged this way" below).

3. Leave the Compose file location at `docker-compose.yaml`.

4. Assign your domain to the **`app`** service. It listens on port 80, so the domain
   alone is enough for Coolify's proxy to route to it; no port needs appending. Do not
   assign a domain to any other service.

5. Open **Environment Variables**. Coolify will have created an entry for every
   `${...}` in the Compose file, with generated values already filled in for the
   passwords. Set these by hand:

   | Variable | Notes |
   |---|---|
   | `APP_NAME` | Shown in the panel and in emails. |
   | `APP_URL` | Defaults to the generated domain. Set it explicitly if you assigned your own — canonical URLs, hreflang, sitemap entries and signed preview links are all built from it. |
   | `CMS_FRONTEND_URL` | The public site's base URL, when it is a separate deployment — the normal case for this headless Core. Leave empty to fall back to `APP_URL`. |
   | `CMS_BRAND_PRIMARY` | Panel accent colour. |
   | `MAIL_*` | Defaults to the `log` driver, which writes mail to the container log instead of sending it. |

6. Deploy. The stack starts in order: MySQL and Redis become healthy, `migrate` applies
   the schema and exits, then `app`, `worker` and `scheduler` start.

7. **Seed the first-run data**, once only, from Coolify's terminal on the `app` service:

   ```bash
   php artisan db:seed --force
   ```

   This creates the branded 404 page and the settings records. It is deliberately not
   automatic: running it on every deploy is how seeders start fighting real content.

8. Create your administrator, then sign in at `/admin` and complete MFA enrolment.
   **This is not optional** — RULE #5 makes multi-factor mandatory and the panel
   challenges before authentication completes. Store the recovery codes where the
   client can reach them; without them, a lost authenticator needs a database edit.

9. If you kept Meilisearch, build the indexes:

   ```bash
   php artisan scout:import "App\Models\Content"
   ```

10. Confirm the deployment is sound:

    ```bash
    php artisan cms:audit-rules
    ```

    All nine rules should report PASS. This is the check that inspects the *running*
    application rather than the source, so it is the one that catches a deployment
    problem the test suite cannot see.

## APP_KEY — the one irreversible setting

`APP_KEY` is generated once by Coolify (via `SERVICE_REALBASE64_32_APP`) and then
persists across deployments.

**Copy it into the client's password manager after the first deploy.**

It encrypts, among other things, the multi-factor secret of every administrator. Change
it or lose it and every admin is locked out of the panel, with no route back except a
database edit. There is no recovery path that does not involve one.

The container refuses to start if `APP_KEY` is missing, not `base64:`-prefixed, or does
not decode to 32 bytes. That last check exists for a specific failure: if the Compose
interpolation does not expand, the value arrives as the literal string
`base64:${SERVICE_REALBASE64_32_APP}`, and Laravel's own error for that
("Unsupported cipher or incorrect key length") points nowhere near the cause. The
entrypoint names the problem and the command that fixes it instead.

## What runs, and why each one is needed

| Service | Role | Required? |
|---|---|---|
| `app` | nginx + php-fpm | Yes — assign the domain here |
| `migrate` | Applies migrations, then exits | Yes |
| `worker` | Queue worker | **Yes** |
| `scheduler` | `schedule:work` | Yes |
| `mysql` | MySQL 8 | **Yes** — not interchangeable with SQLite |
| `redis` | Cache and queue backend | Yes |
| `meilisearch` | Search | Optional |

**The worker is not optional.** Image conversions, search indexing and sitemap
regeneration are all queued. Without it, uploads succeed and thumbnails never appear —
again, with no error anywhere.

**MySQL is not interchangeable with SQLite.** Per-locale slug uniqueness (Decision D-1)
is enforced by stored generated columns with unique indexes, which SQLite cannot
express. On SQLite the guarantee silently degrades to the application-level check alone.

**Meilisearch is genuinely optional.** Its absence returns a structured 503 from
`/api/v1/search` and degrades nothing else. To drop it: delete the service and its
volume, and set `SCOUT_DRIVER=collection`, which falls back to database matching.

`migrate` is a separate one-shot service rather than something the app does at startup,
so exactly one process touches the schema. With three containers starting together,
startup migrations become a race — and the loser reports a confusing partial failure.

## Why the build is arranged this way

Two ordering constraints are easy to get wrong, and both were found by the build
failing:

**Assets need `vendor/`.** The Filament custom theme imports Filament's own stylesheet
out of `vendor/`, and Tailwind scans `app/Filament` for class names. A Node-only asset
stage cannot resolve the import — the first version of this Dockerfile failed exactly
there. So Composer runs first and the asset stage copies `vendor/` from it.

**The asset build is not optional.** `public/build` is gitignored, and Filament's own
published CSS/JS are generated by `filament:upgrade` during the Composer script phase.
An image missing either boots successfully and then serves an unstyled admin panel.

**Caches are written at startup, not at build time.** They bake in configuration, and
configuration is not known until runtime — a config cache built into the image would
carry build-machine settings into every environment. The entrypoint writes them before
php-fpm starts, which matters because `opcache.validate_timestamps=0` means PHP will
never notice a file written afterwards.

## Scaling and tuning

`PHP_FPM_MAX_CHILDREN` (default 12) is the main dial; budget roughly 64 MB per child
under this extension set. Add it as an environment variable in Coolify to change it.

To run more than one queue worker, raise the replica count on `worker`. Do **not**
replicate `scheduler` — two schedulers run every scheduled task twice.

## Image size

The image is large by PHP standards, mostly because it includes `ffmpeg` for `ffprobe`
(Decision D-6). With it, video duration and dimensions are read on upload; without it
the panel requires a hand-made thumbnail for every locally hosted video, because
Google's video sitemap format requires a thumbnail.

Remove `ffmpeg` from the `apt-get install` line in the Dockerfile to save roughly
250 MB. The code path for its absence is deliberate and tested — this is a supported
configuration, not a degraded one.

## Troubleshooting

**Domain shows "No Available Server".** Check that the domain is on the `app` service
and that `app` is healthy. Its health check is `GET /up`; if that fails, read the `app`
logs — the entrypoint prints exactly which step it reached.

**Panel loads unstyled.** `public/build` is missing, which means the asset stage did not
run or its output was not copied. Rebuild without cache.

**Media 404s.** Check that the `cms-media` volume is mounted at
`/var/www/html/storage/app/public` and that `public/storage` is a symlink to it. The
entrypoint repairs a missing symlink and logs when it does.

**Uploads fail with a permission error.** The entrypoint takes ownership of the media
volume on first start. If it was skipped, the volume is owned by root while php-fpm runs
as `www-data`.

**A setting in Coolify seems to be ignored.** Redeploy rather than restart. Config is
cached at container start, so a changed environment variable needs a new container.

**Everything works on the command line but web requests report no database password.**
This is the classic php-fpm trap and is already handled: `clear_env = no` in
`docker/php-fpm-pool.conf`. If that line is ever removed, php-fpm wipes the environment
of its workers, so `php artisan` and the queue worker (both CLI) keep working while only
web requests fail.
