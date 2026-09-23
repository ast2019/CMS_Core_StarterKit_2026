#!/bin/sh
set -eu

# =============================================================================
# Container entrypoint.
#
# Runs for EVERY role — web, worker, scheduler, migration — because each one needs the
# same validated configuration and the same warm caches. The role itself is whatever
# was passed as the command; this script prepares the environment and then hands over
# with exec, so the real process keeps PID 1 and receives signals directly.
#
# Written for /bin/sh, not bash: the php:8.4-fpm image is Debian, so bash exists, but
# nothing here needs it and dash starts faster.
# =============================================================================

fail() {
    echo "" >&2
    echo "FATAL: $1" >&2
    echo "" >&2
    exit 1
}

# -----------------------------------------------------------------------------
# 1. Validate APP_KEY before anything else.
#
# This fails closed, loudly, with the command that fixes it — because the failure it
# prevents is both catastrophic and hard to read. APP_KEY encrypts, among other things,
# the multi-factor secrets of every admin account (RULE #5). A container that starts
# with the wrong key locks every administrator out of the panel, and a container that
# generates a NEW key on each boot silently destroys those secrets.
#
# It also catches a specific, likely mistake: if the Compose interpolation for the
# generated key does not expand, APP_KEY arrives as the literal string
# "base64:${SERVICE_REALBASE64_32_APP}". Laravel's own error for that is
# "Unsupported cipher or incorrect key length", which points nowhere near the cause.
# -----------------------------------------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
    fail "APP_KEY is not set.
Generate one and set it in your deployment's environment variables:
    php artisan key:generate --show
Store it somewhere permanent. Changing it later makes existing encrypted data —
including every admin's two-factor secret — unreadable."
fi

case "${APP_KEY}" in
    base64:*) ;;
    *) fail "APP_KEY must be a base64-encoded 32-byte key, prefixed with 'base64:'.
Got a value starting with: $(printf '%.12s' "${APP_KEY}")…
Generate a valid one with: php artisan key:generate --show" ;;
esac

APP_KEY_BYTES=$(printf '%s' "${APP_KEY#base64:}" | base64 -d 2>/dev/null | wc -c | tr -d ' ')

if [ "${APP_KEY_BYTES}" != "32" ]; then
    fail "APP_KEY does not decode to 32 bytes (got ${APP_KEY_BYTES}).
If the value looks like 'base64:\${SERVICE_REALBASE64_32_APP}', the deployment
platform did not substitute it — set APP_KEY explicitly instead.
Generate a valid key with: php artisan key:generate --show"
fi

# -----------------------------------------------------------------------------
# 2. Writable paths.
#
# storage/app/public is a mounted volume (RULE #9 — media lives on local disk). A
# freshly created volume is owned by root, and php-fpm runs as www-data, so without
# this every upload fails with a permission error that looks like an application bug.
#
# Ownership is CHECKED before being changed: a site with tens of thousands of media
# files would otherwise pay a recursive chown on every single container start.
# -----------------------------------------------------------------------------
MEDIA_PATH="/var/www/html/storage/app/public"

mkdir -p \
    "${MEDIA_PATH}" \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache

if [ "$(stat -c '%U' "${MEDIA_PATH}")" != "www-data" ]; then
    echo "Taking ownership of the media volume (first start with this volume)…"
    chown -R www-data:www-data "${MEDIA_PATH}"
fi

chown www-data:www-data \
    /var/www/html/storage \
    /var/www/html/storage/framework \
    /var/www/html/storage/framework/cache \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache

# The symlink is created at build time, but a bind mount over public/ could shadow it,
# and a missing link means every image on the site 404s — a failure worth repairing
# rather than reporting.
if [ ! -e /var/www/html/public/storage ]; then
    echo "public/storage symlink is missing; recreating it…"
    php artisan storage:link
fi

# -----------------------------------------------------------------------------
# 3. Caches.
#
# Built HERE rather than in the Dockerfile because they bake in configuration, and
# configuration is not known until runtime — an image with a baked config cache would
# carry the build machine's settings into every environment.
#
# This must also happen before php-fpm starts: docker/php.ini sets
# opcache.validate_timestamps=0, so PHP will never notice a file written afterwards.
# -----------------------------------------------------------------------------
echo "Warming caches…"

php artisan config:cache
php artisan view:cache

# route:cache is attempted but not required. Laravel's health endpoint (/up) is a
# closure-based route, and closures cannot always be serialised; a failure here costs
# a little per-request routing time and nothing else, so it must not stop a deploy.
if php artisan route:cache 2>/dev/null; then
    echo "  routes cached"
else
    echo "  routes NOT cached (a route is not serialisable; this is not fatal)"
    php artisan route:clear || true
fi

# Filament's own component and icon caches. Guarded on the command existing so a
# Filament upgrade that renames it degrades to slower boots rather than a failed deploy.
if php artisan list --raw 2>/dev/null | grep -q '^filament:optimize'; then
    php artisan filament:optimize
fi

# -----------------------------------------------------------------------------
# 4. Optional migrations.
#
# Default OFF. docker-compose.yaml runs migrations in a dedicated one-shot service, so
# exactly one process migrates — with this on, the web, worker and scheduler containers
# would race each other to run the same migrations on every deploy.
#
# The switch exists for single-container deployments that have no init service.
# -----------------------------------------------------------------------------
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "RUN_MIGRATIONS=true — applying migrations…"
    php artisan migrate --force --isolated
fi

echo "Starting: $*"

exec "$@"
