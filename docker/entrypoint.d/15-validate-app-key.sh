#!/bin/sh
script_name="validate-app-key"

# =============================================================================
# Refuse to start without a usable APP_KEY.
#
# Numbered 15 so it runs after the web server configuration (10-*) and before the
# image's Laravel automations (50-*). Ordering is the point: config:cache is the first
# thing to fail on a bad key, and its error — "Unsupported cipher or incorrect key
# length" — says nothing about where the key came from.
#
# This matters more than a normal startup check. APP_KEY encrypts every administrator's
# multi-factor secret (RULE #5). A container that starts with the wrong key locks every
# admin out of the panel; one that generates a fresh key on each boot destroys those
# secrets permanently. Failing to start is the kindest outcome available.
#
# The 32-byte check exists for one specific, likely mistake: when a deployment platform
# does not substitute its own variable, APP_KEY arrives as the literal string
# "base64:${SERVICE_REALBASE64_32_APP}".
# =============================================================================

fail() {
    echo "" >&2
    echo "❌ ERROR ($script_name): $1" >&2
    echo "" >&2
    exit 1
}

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

app_key_bytes=$(printf '%s' "${APP_KEY#base64:}" | base64 -d 2>/dev/null | wc -c | tr -d ' ')

if [ "${app_key_bytes}" != "32" ]; then
    fail "APP_KEY does not decode to 32 bytes (got ${app_key_bytes}).
If the value still looks like 'base64:\${SERVICE_REALBASE64_32_APP}', the deployment
platform did not substitute it — set APP_KEY explicitly instead.
Generate a valid key with: php artisan key:generate --show"
fi
