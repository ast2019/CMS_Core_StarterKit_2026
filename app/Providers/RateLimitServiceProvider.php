<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Requirement 8.7 — rate limits for both API groups, independently configurable.
 *
 * Independent on purpose: the Delivery API serves a public site and needs a
 * generous read limit, while the Management API is used by a handful of
 * authenticated integrations and a high limit there only widens the blast radius of
 * a leaked token.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('cms-delivery', function (Request $request): Limit {
            $perMinute = (int) config('cms.api.delivery.rate_limit', 120);

            /*
             * Keyed by IP. When API-key enforcement is on, keying by the key would be
             * fairer, but it would also let one leaked key be shared across many
             * clients under a single budget — and the public case has no key at all.
             */
            return Limit::perMinute($perMinute)->by($request->ip() ?? 'unknown');
        });

        RateLimiter::for('cms-redirects', function (Request $request): Limit {
            /*
             * Redirect lookups, which the frontend calls on every 404 IT serves — from
             * one server IP for the whole site. Under the shared `cms-delivery` limit a
             * crawler walking a handful of stale URLs would exhaust the budget for every
             * real visitor, and the thing that breaks is redirect handling: exactly the
             * gap this endpoint was added to close.
             *
             * Still keyed by IP and still limited: each call is an in-memory map lookup,
             * but it also increments a hit counter, so an unbounded 404 flood would be
             * an unbounded stream of UPDATEs.
             */
            $perMinute = (int) config('cms.api.delivery.redirect_rate_limit', 600);

            return Limit::perMinute($perMinute)->by('redirects:'.($request->ip() ?? 'unknown'));
        });

        RateLimiter::for('cms-manage', function (Request $request): Limit {
            $perMinute = (int) config('cms.api.management.rate_limit', 60);

            /*
             * Keyed by authenticated user, falling back to IP. Per-user is what makes
             * the limit meaningful here: two integrations behind one NAT must not
             * exhaust each other's budget.
             */
            return Limit::perMinute($perMinute)
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown');
        });

        /**
         * Returns an ARRAY of limits, not one — Laravel applies every limit in the
         * list, which is how the burst and hourly tiers below combine.
         *
         * @return list<Limit>
         */
        RateLimiter::for('cms-contact', function (Request $request): array {
            /*
             * Much tighter than the read limit. The contact form is the only public
             * endpoint that writes a row, so the read allowance of 120/min would be
             * an invitation to flood the submissions table.
             *
             * Two tiers: a short burst limit stops a script, and an hourly limit stops
             * a slow drip that would stay under the per-minute ceiling all day.
             */
            $key = $request->ip() ?? 'unknown';

            return [
                Limit::perMinute(3)->by("contact:min:{$key}"),
                Limit::perHour(20)->by("contact:hour:{$key}"),
            ];
        });
    }
}
