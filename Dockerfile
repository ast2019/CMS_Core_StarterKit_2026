# syntax=docker/dockerfile:1.7

# =============================================================================
# CMS Core Starter Kit — production image
#
# One image serves three roles (web, queue worker, scheduler); docker-compose.yaml
# runs it three times with different commands. Building one image rather than three
# means the worker can never be running different code from the web process, which is
# the usual cause of "the job worked yesterday" after a partial deploy.
#
# The image contains NO configuration and NO secrets. Everything comes from the
# environment at runtime, so the same image can be promoted between environments.
# =============================================================================

# -----------------------------------------------------------------------------
# Stage 1 — base: PHP with every extension this application needs.
#
# Shared by the dependency and runtime stages so the extension build happens once.
# -----------------------------------------------------------------------------
FROM php:8.4-fpm-bookworm AS base

# PHP 8.4 is a hard floor, not a preference: spatie/laravel-activitylog 5.x,
# laravel-sitemap 8.x and schema-org 5.x all require ^8.4.

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# docker/php-fpm-pool.conf interpolates these from the environment. They need defaults
# HERE, not there: php-fpm refuses to start when a referenced variable is empty, so an
# unset value would fail the container rather than fall back. Raise them for a busier
# site — each child is roughly 64 MB of PHP under this extension set.
ENV PHP_FPM_MAX_CHILDREN=12 \
    PHP_FPM_START_SERVERS=3 \
    PHP_FPM_MIN_SPARE_SERVERS=2 \
    PHP_FPM_MAX_SPARE_SERVERS=5

# ffmpeg supplies ffprobe, which is a SOFT dependency (Decision D-6): with it, video
# duration and dimensions are read on upload; without it the panel demands a manual
# thumbnail for every locally hosted video, because Google's video sitemap requires a
# thumbnail. It is included because "upload a thumbnail by hand, every time" is a
# support burden. Drop it to save roughly 250 MB — the code path is designed for its
# absence and is tested.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        curl \
        ffmpeg \
        # procps for ps/pgrep. Deliberate: when a queue worker is wedged or php-fpm is
        # saturated, the first thing anyone needs is a process list, and discovering
        # that `ps` is missing while diagnosing a live incident is a poor time to learn it.
        procps \
    && rm -rf /var/lib/apt/lists/*

# install-php-extensions resolves the system libraries each extension needs. Doing
# this by hand is the usual source of a gd without WebP support, which would silently
# break Media Library's conversions.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

# gd + exif: Media Library conversions. Without gd every image upload fails at
#            conversion time, so it is not optional in practice.
# intl:      locale-aware formatting for fa/en/ar.
# pdo_mysql: MySQL is required in production for Decision D-1.
# redis:     REDIS_CLIENT=phpredis.
# pcntl:     queue:work needs it to shut down gracefully on SIGTERM instead of
#            being killed mid-job on every redeploy.
# opcache:   see docker/php.ini.
RUN install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        redis \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# Stage 2 — PHP dependencies.
#
# Only the manifests are copied, so editing a controller does not re-resolve Composer.
#
# --no-scripts --no-autoloader because the scripts (package:discover, filament:upgrade)
# need the application source, which is not here yet. Built on `base` rather than the
# composer image because the lockfile's transitive platform requirements (ext-gd,
# ext-intl, PHP 8.4) must actually be satisfied for the install to resolve.
# -----------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-progress

# -----------------------------------------------------------------------------
# Stage 3 — front-end assets.
#
# Built in a separate stage so Node never reaches the runtime image.
#
# This stage is NOT optional. tests/Architecture/NoExternalCdnTest scans the built CSS
# for external font and CDN hosts (RULE #4), public/build is gitignored, and the panel
# has no styling without it. An image without this stage boots and then renders an
# unstyled admin.
#
# It needs vendor/ — which is not obvious. The Filament custom theme (RULE #4) imports
# Filament's own stylesheet out of vendor/, and Tailwind scans vendor/laravel/framework
# for pagination views, so a Node-only stage fails to resolve the import.
# -----------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources

# Tailwind's @source globs in the theme reach into app/Filament, so the class names
# used by the panel's PHP are discovered rather than purged from the built CSS.
COPY app ./app

COPY --from=vendor /var/www/html/vendor ./vendor

# app.css lists storage/framework/views as a source glob; an absent directory is not
# worth a build failure.
RUN mkdir -p storage/framework/views

# Vazirmatn is referenced as an absolute URL (/fonts/vazirmatn/…), so the woff2 files
# are not inputs to this build — they ship as static files in public/fonts and are
# served directly. That is what makes the build work with no network access (RULE #4).
RUN npm run build

# -----------------------------------------------------------------------------
# Stage 4 — runtime.
# -----------------------------------------------------------------------------
FROM base AS runtime

COPY --from=vendor /var/www/html/vendor ./vendor

# .dockerignore excludes vendor/ and public/build, so neither overwrites what the
# earlier stages produced.
COPY . .

COPY --from=assets /app/public/build ./public/build

# Generates the optimised autoloader and runs the deferred Composer scripts.
# filament:upgrade runs filament:assets, which publishes Filament's own CSS/JS into
# public/ — gitignored, so it exists only because this step runs.
RUN composer dump-autoload --no-dev --optimize

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# RULE #9 — media is served from local disk, so public/storage must point into
# storage/app/public. The link is created here, at build time, rather than at startup:
# the path is fixed inside the image, and creating it now means the runtime user never
# needs write access to public/. docker-compose.yaml mounts a named volume over the
# target, and this symlink resolves into that volume.
RUN php artisan storage:link \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# No VOLUME instruction for storage/app/public: it would create an anonymous volume on
# any `docker run` that forgets to mount one, which looks like it works and then
# discards every uploaded file when the container is replaced. Requiring the mount to
# be explicit makes losing media a visible mistake rather than a silent default.

EXPOSE 80

HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=5 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf", "-n"]
