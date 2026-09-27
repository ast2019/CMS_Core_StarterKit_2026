#!/bin/sh
script_name="cms-sync-release"

# =============================================================================
# Bring the database up to the release this image carries (RULES #1 and #2).
#
# `php artisan cms:release` bumps system_info and inserts a changelogs row only in the
# database it runs against — a developer's. Only CHANGELOG.md reaches production, so
# without this step a deployed panel keeps showing the version it was installed at, with
# none of the later releases recorded.
#
# Numbered 60 so it runs after the image's Laravel automations (50-*), which run the
# migrations: the tables this writes to must exist first. When they do not (migrations
# disabled), `cms:sync-release` itself warns and exits 0.
#
# Idempotent and forward-only, so running it on every start — and in every container
# started from this image — is safe. Two containers starting together race on the same
# rows; both tables are written so that the loser's duplicate insert fails on a unique
# key and is treated as already done.
#
# A failure here WARNS and lets the container start. The version label and the release
# history are not worth taking the site down for, and a real database problem will
# already have failed the migrations above. Run it by hand to see the full error:
#     php artisan cms:sync-release
#
# Set CMS_SYNC_RELEASE=false to skip it.
# =============================================================================

: "${APP_BASE_DIR:=/var/www/html}"
: "${CMS_SYNC_RELEASE:=true}"

if [ "${CMS_SYNC_RELEASE}" = "false" ]; then
    echo "ℹ️ ($script_name): skipped because CMS_SYNC_RELEASE=false."
    exit 0
fi

echo "🚀 ($script_name): syncing the release history: \"php artisan cms:sync-release\"..."

if ! php "$APP_BASE_DIR/artisan" cms:sync-release --no-interaction; then
    echo "" >&2
    echo "⚠️ WARNING ($script_name): cms:sync-release failed; the panel may show an older version" >&2
    echo "and an incomplete changelog until it succeeds. Run it by hand to see why:" >&2
    echo "    php artisan cms:sync-release" >&2
    echo "" >&2
fi

exit 0
