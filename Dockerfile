# syntax=docker/dockerfile:1.7

# =============================================================================
# CMS Core Starter Kit — production image
#
# ONE image, and one deployment unit: the `web` stage serves the panel and both APIs,
# and can also run the queue worker and the scheduler. A single container plus MySQL is
# a complete, working deployment — nothing else has to be provisioned.
#
# Built on serversideup/php, which supplies nginx + PHP-FPM supervised by S6 Overlay,
# production defaults, and configuration through environment variables. That replaces a
# hand-rolled nginx.conf, php.ini, FPM pool and supervisord.conf — roughly 300 lines of
# infrastructure this project no longer owns, maintains, or gets wrong.
#
# It runs UNPRIVILEGED (www-data, uid/gid 33) and listens on 8080, because a non-root
# process cannot bind 80. Both facts matter when configuring the deployment; see
# docs/coolify.md.
#
# The earlier stages exist only to build it:
#
#   php-base  PHP with the extensions this application needs
#   vendor    Composer dependencies
#   assets    the Vite build
#   app       the assembled /var/www/html
#   web       the image you deploy
#
# Build it with no --target; `web` is last, so it is the default.
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
# Stage 3 — app: the assembled application.
#
# Not a runnable image by itself — it holds the finished /var/www/html for the stages
# below, so the application payload is built exactly once no matter how many images use
# it. That is what guarantees a worker cannot be running different code from the web
# process, which is the usual cause of "the job worked yesterday" after a partial deploy.
# -----------------------------------------------------------------------------
FROM php-base AS app

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
# Stage 4 — web: THE deployment image.
#
# nginx + PHP-FPM, and self-sufficient: it can also run the queue worker and the
# scheduler, so a single container is a complete deployment. That is why ffmpeg lives
# here rather than in a separate worker image.
#
# Listens on 8080, not 80, because it runs unprivileged (www-data, uid 33).
# -----------------------------------------------------------------------------
FROM serversideup/php:${PHP_VERSION}-fpm-nginx-${SSU_VERSION} AS web

USER root

RUN install-php-extensions gd intl bcmath exif

# ffprobe (Decision D-6), for reading an uploaded video's duration and dimensions.
#
# OFF by default, because it costs ~410 MB and most sites do not need it: an EMBEDDED
# video (YouTube and friends) supplies its own thumbnail and duration, so ffprobe is only
# useful for video files hosted on this server.
#
# Turn it on for a site that does host video:
#
#     WITH_FFMPEG=true
#
# as a build variable in Coolify, or `docker build --build-arg WITH_FFMPEG=true`.
#
# Without it, the panel asks an editor for the duration instead. That is a deliberate and
# tested path, not a degraded one — App\Listeners\ExtractVideoMetadata checks for the
# binary and stays quiet when it is absent.
ARG WITH_FFMPEG=false

RUN if [ "${WITH_FFMPEG}" = "true" ]; then \
        apt-get update \
        && apt-get install -y --no-install-recommends ffmpeg \
        && rm -rf /var/lib/apt/lists/*; \
    else \
        echo "ffmpeg omitted (WITH_FFMPEG=false); video duration will be entered manually"; \
    fi

USER www-data

WORKDIR /var/www/html

COPY --from=app --chown=www-data:www-data /var/www/html /var/www/html

# Refuse to start on a missing or malformed APP_KEY. Numbered 15 so it runs after the
# web server configuration (10-*) and before the image's Laravel automations (50-*) —
# otherwise a bad key surfaces as a confusing failure inside `php artisan config:cache`.
COPY docker/entrypoint.d/15-validate-app-key.sh /etc/entrypoint.d/15-validate-app-key.sh

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

# Raised from the image's 256M default because Media Library decodes an upload into a
# bitmap to build the thumbnail and WebP variants, and a large photograph exhausts 256M.
# Set as an image default rather than left to the deployment, because an out-of-memory
# conversion fails the job rather than the request — so it is invisible until someone
# notices a missing thumbnail.
ENV PHP_MEMORY_LIMIT=512M

# A form with many translatable fields across three locales exceeds the 1000 default,
# and PHP silently DISCARDS the excess rather than erroring — so the failure looks like
# the panel dropping the last few fields of a long article.
ENV PHP_MAX_INPUT_VARS=5000

# Editors upload video; both limits must be raised together, and whichever is lower
# wins. nginx rejects an oversized body before PHP sees it, producing a bare 413 with no
# Laravel validation message.
ENV PHP_UPLOAD_MAX_FILE_SIZE=64M \
    PHP_POST_MAX_SIZE=68M \
    NGINX_CLIENT_MAX_BODY_SIZE=68M
