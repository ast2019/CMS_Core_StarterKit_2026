<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honours the `maintenance_mode` setting on the Delivery API.
 *
 * Requirement 3.1 (Settings includes maintenance_mode).
 *
 * This is the CMS's own switch, distinct from `php artisan down`: the framework's
 * maintenance mode takes the whole application offline including the admin panel,
 * which is the opposite of what an editor wants when they flip this toggle to
 * work on the site privately.
 *
 * 503 with Retry-After is the correct signal — it tells crawlers to come back
 * rather than to de-index, which a 404 or an empty 200 would invite.
 */
class EnsureMaintenanceModeAllowsDelivery
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Setting::isMaintenanceMode()) {
            return $next($request);
        }

        return response()->json([
            'message' => 'The site is currently unavailable for maintenance.',
        ], Response::HTTP_SERVICE_UNAVAILABLE)
            ->withHeaders([
                'Retry-After' => '3600',
                'Cache-Control' => 'no-store',
            ]);
    }
}
