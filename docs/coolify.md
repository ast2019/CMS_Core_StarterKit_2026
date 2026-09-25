# Deploying on Coolify

One container plus MySQL is a complete deployment. Nothing else has to be provisioned.

The `Dockerfile` builds a single self-sufficient image — nginx + PHP-FPM, and able to run
the queue worker and the scheduler too. It is built on
[serversideup/php](https://serversideup.net/open-source/docker-php/), which supplies the
web server, production defaults and configuration through environment variables.

`docs/deployment.md` covers the host-agnostic operational decisions and still applies.

## Three things that will bite you if skipped

**1. `Ports Exposes` must be `8080`.** The image runs unprivileged, so it cannot bind 80.
Set the port in the application's settings; the domain itself is entered normally, with
no port appended. Get this wrong and the domain shows "No Available Server".

**2. Media needs persistent storage.** Media lives on local disk by design (RULE #9 —
there is no S3 to fall back on, and an architecture test fails the build if a cloud
adapter appears). Without a volume, every image, video and document the client uploaded
is destroyed on the next redeploy.

It fails in the worst way available: nothing errors. The panel loads, uploads work, and
the library is simply empty — so the loss is usually noticed days later, by the client, on
the public site.

**3. A queue worker is required.** Image conversions, search indexing, sitemap
regeneration and video metadata extraction are all queued. Without one, uploads succeed
and thumbnails are never generated — again, with no error anywhere. See
[Worker and scheduler](#worker-and-scheduler).

## Step 1 — MySQL

**New Resource → Database → MySQL 8.**

MySQL is required and not interchangeable with SQLite: per-locale slug uniqueness
(Decision D-1) is enforced by stored generated columns with unique indexes, which SQLite
cannot express.

Copy the hostname, database, username and password from the database's own page.

Then, in the application you create next, enable
**Configuration → Advanced → Connect To Predefined Network**. Without it the application
and the database sit on separate Docker networks and cannot reach each other.

Nothing else is needed. Redis and Meilisearch are optional; see
[Optional extras](#optional-extras).

## Step 2 — the application

**New Resource → Application → Git repository**, pointing at this repo, branch `main`.

| Setting | Value |
|---|---|
| Build Pack | **`Dockerfile`** |
| Dockerfile Location | `/Dockerfile` |
| Build Stage | leave empty (`web` is the last stage, so it is the default) |
| **Ports Exposes** | **`8080`** |
| Healthcheck Path | `/up` |
| Domain | `https://cms.example.com` — no port suffix |

## Step 3 — environment variables

Ten variables. Everything else has a correct default, either in Laravel or baked into the
image.

```env
APP_KEY=base64:REPLACE_ME
APP_URL=https://cms.example.com

DB_CONNECTION=mysql
DB_HOST=<hostname from the MySQL resource>
DB_PORT=3306
DB_DATABASE=cms_core
DB_USERNAME=cms
DB_PASSWORD=<from the MySQL resource>

AUTORUN_ENABLED=true
AUTORUN_LARAVEL_MIGRATION=true
```

Generate the key on any machine with PHP or a shell:

```bash
head -c 32 /dev/urandom | base64      # prefix the result with base64:
```

Optionally also set `APP_NAME`, and `CMS_FRONTEND_URL` when the public site is a separate
deployment.

### Variables you do not need to set

Listed because setting them is how deployments go wrong, not because it is interesting:

| Variable | Default | Why it is already right |
|---|---|---|
| `APP_ENV` | `production` | Laravel's default when unset |
| `APP_DEBUG` | `false` | Laravel's default when unset |
| `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER` | `database` | Uses the MySQL you already have |
| `SCOUT_DRIVER` | `collection` | Search runs against the database |
| `TRUSTED_PROXIES` | `*` | Correct when the container is only reachable through the proxy |
| `PHP_MEMORY_LIMIT` | `512M` | Set in the image; Media Library needs it for conversions |
| `PHP_MAX_INPUT_VARS` | `5000` | Set in the image; translatable forms exceed the 1000 default |
| `PHP_UPLOAD_MAX_FILE_SIZE`, `NGINX_CLIENT_MAX_BODY_SIZE` | `64M` / `68M` | Set in the image, and raised together |
| `HEALTHCHECK_PATH` | `/up` | Set in the image |
| `PHP_OPCACHE_*` | enabled, sized for Filament | Set in the image |

**Do not set `CACHE_STORE=redis` unless Redis actually exists.** The health check still
passes without it — `/up` does not touch the cache — so Coolify reports a green, healthy
deployment while every API endpoint returns 500.

## Step 4 — persistent storage

**Configuration → Persistent Storage → Add**:

```
Destination Path: /var/www/html/storage/app/public
```

That single mount is what stands between the client's media and a redeploy. `public/storage`
is a symlink into it, created at container start.

A Coolify-managed volume inherits the ownership of the image directory it covers, so it is
writable even though the container runs unprivileged. If you instead bind-mount a host
path, create and chown it first, because a bind mount keeps the host's ownership and a new
directory belongs to root:

```bash
mkdir -p /data/cms-core/media
chown -R 33:33 /data/cms-core/media    # 33 = www-data inside the image
```

Back the media up together with the database. A database-only backup restores records
pointing at files that no longer exist.

## Step 5 — deploy, then finish the setup

Deploy. Migrations run at container start (`AUTORUN_LARAVEL_MIGRATION`), so the schema is
ready by the time the container is healthy.

Then, in **Terminal** on the application:

```bash
# First deploy only: the version row, the settings records and the branded 404 page.
# NOT `db:seed --force` — that seeder generates demo content with model factories, which
# need fakerphp/faker, a dev dependency absent from this image.
php artisan db:seed --class=InstallSeeder --force

# Your own account.
php artisan make:filament-user

# The role column defaults to the least-privileged role on purpose, so a new
# account is a viewer until promoted.
php artisan tinker --execute="App\Models\User::where('email','you@example.com')->update(['role'=>'admin'])"

# Remove the demo accounts the seeder created.
php artisan tinker --execute="App\Models\User::where('email','like','%@example.test')->delete()"

# Confirm the deployment is sound: all nine §12 rules should report PASS.
php artisan cms:audit-rules
```

Sign in at `/admin` and complete MFA enrolment. **Not optional** — RULE #5 makes
multi-factor mandatory and the panel challenges before authentication completes. Store the
recovery codes where the client can reach them; without them a lost authenticator needs a
database edit.

Finally, copy `APP_KEY` into the client's password manager. It encrypts every
administrator's two-factor secret, so changing or losing it locks everyone out of the panel
with no route back but a database edit. The container refuses to start without a valid key,
including when a deployment platform fails to substitute a placeholder.

## Worker and scheduler

Both run inside the same container, as **Scheduled Tasks** —
**Configuration → Scheduled Tasks → Add**:

| Name | Command | Frequency |
|---|---|---|
| Queue | `php artisan queue:work --stop-when-empty --max-time=55` | `* * * * *` |
| Scheduler | `php artisan schedule:run` | `* * * * *` |

The queue task drains whatever is waiting and exits, so the next minute's run starts
cleanly; `--max-time=55` stops two runs from overlapping.

For a busy site, run a long-lived worker instead: a second application from the same repo
with **Build Stage** `app`, no domain, no health check, the same environment and the same
storage mount, and a start command of
`php artisan queue:work --tries=3 --max-time=3600`. Set
`AUTORUN_LARAVEL_MIGRATION=false` on it so only one process owns the schema.

Do not run more than one scheduler — two schedulers run every scheduled task twice.

## Optional extras

Neither is needed to run the CMS. Add them when there is a reason.

**Redis** — faster, and the only store supporting cache tags, which lets the Delivery API
invalidate by tag instead of flushing the whole cache on publish. Create the resource,
then set `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `REDIS_HOST`, `REDIS_PORT=6379`,
`REDIS_PASSWORD`.

**Meilisearch** — typo tolerance and relevance ranking. Without it, search still works
against the database, but it has neither and slows as content grows. Create the resource,
then set `SCOUT_DRIVER=meilisearch`, `MEILISEARCH_HOST`, `MEILISEARCH_KEY`, and build the
per-locale indexes:

```bash
php artisan scout:import "App\Models\Content"
```

## Updating

Push to `main`, or press Redeploy. Migrations apply at startup and the media volume is
untouched.

## Troubleshooting

| Symptom | Cause |
|---|---|
| "No Available Server" | `Ports Exposes` is not `8080` |
| Cannot connect to the database | "Connect To Predefined Network" is not enabled |
| Panel loads unstyled | Build Stage was set to something other than `web`/empty |
| Migrations did not run | `AUTORUN_LARAVEL_MIGRATION` is not `true` |
| Media empty after a redeploy | No persistent storage on `/var/www/html/storage/app/public` |
| Uploads fail with a permission error | Bind-mounted host path not `chown 33:33` |
| Thumbnails never appear | No queue worker running |
| API returns 500 while `/up` is green | `CACHE_STORE`/`QUEUE_CONNECTION` point at a Redis that does not exist |
| Login succeeds then returns to the form | Serving over plain HTTP; set `PHP_SESSION_COOKIE_SECURE=0` |
| A changed variable seems ignored | Redeploy rather than restart — config is cached at container start |
| Video duration is not filled in | ffprobe runs on the queue; check a worker is running |

Do not use a **Post-deployment Command** for migrations. Coolify marks the deployment
successful before running it, and a failure there does not change that status — so a broken
migration passes silently. `AUTORUN_LARAVEL_MIGRATION` runs at startup and fails the
container properly.
