# syntax=docker/dockerfile:1.7

# =============================================================================
# CMS Core Starter Kit — production images
#
# Built on serversideup/php, which supplies nginx + PHP-FPM supervised by S6 Overlay,
# sensible production defaults, and configuration through environment variables. That
# replaces a hand-rolled nginx.conf, php.ini, FPM pool and supervisord.conf — roughly
# 300 lines of infrastructure this project no longer owns, maintains, or gets wrong.
#
# It also runs UNPRIVILEGED (www-data, uid/gid 33) rather than root, which matters for
# the media volume; see docs/coolify.md.
#
# TWO images, from one source tree:
#
#   web  — nginx + PHP-FPM. Serves the panel and both APIs. Listens on 8080.
#   cli  — no web server. Runs the queue worker, the scheduler and migrations.
#
# Two rather than one because these images are supervised differently: S6 starts nginx
# and FPM in the web image, so using it for a queue worker would run a web server
# alongside the worker and let the health check pass on a container whose worker had
# died. The cli variant has no supervisor and runs the given command directly.
# =============================================================================

ARG PHP_VERSION=8.4
# Pinned so a rebuild months from now produces the same base. Tag order is
# {php}-{variation}-{version}; `serversideup/php:8.4-cli` (unpinned) also works.
ARG SSU_VERSION=v4.5.1

# -----------------------------------------------------------------------------
# Stage 1 — PHP with the extensions this application needs.
#
# Shared by the dependency stage and the cli image so the extension build happens once.
#
# It has to come before Composer, not after: `composer install` validates the platform
# requirements of every package in the lockfile, and this dependency set needs ext-intl
# and ext-exif. Installing them afterwards fails the install with a message about
# --ignore-platform-req, which is the wrong fix.
#
# The base image already ships pdo_mysql, redis, pcntl, zip, mbstring and OPcache. These
# four it does not:
#
#   gd + exif — Media Library conversions. Without gd every image upload fails at
#               conversion time, so it is not optional in practice.
#   intl      — locale-aware formatting for fa/en/ar.
#   bcmath    — required by the dependency set.
# -----------------------------------------------------------------------------
FROM serversideup/php:${PHP_VERSION}-cli-${SSU_VERSION} AS php-base

USER root
RUN install-php-extensions gd intl bcmath exif
USER www-data

WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# Stage 2 — PHP dependencies.
#
# Only the manifests are copied, so editing a controller does not re-resolve Composer.
#
# --no-scripts --no-autoloader because the scripts (package:discover, filament:upgrade)
# need the application source, which is not here yet.
# -----------------------------------------------------------------------------
FROM php-base AS vendor

COPY --chown=www-data:www-data composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-progress

# -----------------------------------------------------------------------------
# Stage 2 — front-end assets.
#
# Node never reaches a runtime image.
#
# This stage is NOT optional. tests/Architecture/NoExternalCdnTest scans the built CSS
# for external font and CDN hosts (RULE #4), public/build is gitignored, and the panel
# has no styling without it. An image without this stage boots and then renders an
# unstyled admin.
#
# It needs vendor/ — which is not obvious. The Filament custom theme (RULE #4) imports
# Filament's own stylesheet out of vendor/, and Tailwind scans vendor/laravel/framework
# for pagination views, so a Node-only stage cannot resolve the import.
# -----------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources

# Tailwind's @source globs reach into app/Filament, so class names used by the panel's
# PHP are discovered rather than purged from the built CSS.
COPY app ./app

COPY --from=vendor /var/www/html/vendor ./vendor

# app.css lists storage/framework/views as a source glob; an absent directory is not
# worth a build failure.
RUN mkdir -p storage/framework/views

# Vazirmatn is referenced as an absolute URL (/fonts/vazirmatn/…), so the woff2 files
# are not inputs to this build — they ship as static files in public/fonts and are served
# directly. That is what makes the build work with no network access (RULE #4).
RUN npm run build

# -----------------------------------------------------------------------------
# Stage 3 — cli: the assembled application, and the image that runs every
# non-web process.
# -----------------------------------------------------------------------------
FROM php-base AS cli

USER root

# ffprobe (Decision D-6), for reading a video's duration and dimensions.
#
# Installed ONLY in this image, not in web, because App\Listeners\ExtractVideoMetadata
# is queued — so the process that shells out to ffprobe is always a worker. That keeps
# roughly 250 MB out of the image that serves requests.
#
# ffmpeg is a soft dependency: without it the panel asks an editor for the duration, and
# that path is deliberate and tested. Remove this layer to shrink the image.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*

USER www-data

WORKDIR /var/www/html

COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor

# .dockerignore excludes vendor/ and public/build, so neither overwrites what the
# earlier stages produced.
COPY --chown=www-data:www-data . .

COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

# Generates the optimised autoloader and runs the deferred Composer scripts.
# filament:upgrade runs filament:assets, which publishes Filament's own CSS/JS into
# public/ — gitignored, so it exists only because this step runs.
RUN composer dump-autoload --no-dev --optimize

# Refuse to start on a missing or malformed APP_KEY. Runs before the image's own Laravel
# automations (50-*), so a bad key is reported as itself rather than as a confusing
# failure inside `php artisan config:cache`.
COPY --chown=www-data:www-data docker/entrypoint.d/15-validate-app-key.sh /etc/entrypoint.d/15-validate-app-key.sh

USER root
RUN chmod +x /etc/entrypoint.d/15-validate-app-key.sh
USER www-data

# Directories Laravel writes to at runtime. Ownership matters more than it looks: the
# container is unprivileged, so it cannot fix this later — and a fresh Docker named
# volume INHERITS the ownership of the image directory it covers, which is what makes
# the media volume writable without any privileged startup step.
RUN mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache

# No VOLUME instruction for storage/app/public: it would create an anonymous volume on
# any `docker run` that forgets to mount one, which looks like it works and then discards
# every uploaded file when the container is replaced. Requiring the mount to be explicit
# makes losing media a visible mistake rather than a silent default.

# -----------------------------------------------------------------------------
# Stage 4 — web: nginx + PHP-FPM.
# -----------------------------------------------------------------------------
FROM serversideup/php:${PHP_VERSION}-fpm-nginx-${SSU_VERSION} AS web

USER root
RUN install-php-extensions gd intl bcmath exif
USER www-data

WORKDIR /var/www/html

# The application is taken wholesale from the cli stage, so the two images cannot
# contain different code — the usual cause of "the job worked yesterday" after a
# partial deploy.
COPY --from=cli --chown=www-data:www-data /var/www/html /var/www/html

# Additions to the image's own nginx server configuration. Named zz- so it is included
# last from server-opts.d/.
COPY docker/nginx-cms.conf /etc/nginx/server-opts.d/zz-cms.conf

# REPLACES the image's Cloudflare-oriented real-IP config with one that reads
# X-Forwarded-For, which is what this deployment's proxy sends. Replaced rather than
# added because nginx treats a duplicate real_ip_header as a fatal error, not an
# override — discovered by the container refusing to serve anything at all.
COPY docker/nginx-remoteip.conf /etc/nginx/server-opts.d/remoteip.conf

# Laravel's health endpoint, rather than the image's static /healthcheck: a static file
# proves nginx is alive, while /up proves the framework booted and can reach its
# dependencies. That is the difference between "the container is up" and "the site works".
ENV HEALTHCHECK_PATH=/up

# OPcache is off by default in these images (a kindness to developers, wrong for
# production). Sized for Filament, which generates a lot of small classes — the stock
# 10000-file limit is quietly exceeded by this dependency set, after which nothing more
# is cached.
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_MAX_ACCELERATED_FILES=20000 \
    PHP_OPCACHE_MEMORY_CONSUMPTION=192 \
    PHP_OPCACHE_INTERNED_STRINGS_BUFFER=16
