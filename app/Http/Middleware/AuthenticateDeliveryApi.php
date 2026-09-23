<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional API-key enforcement for the Delivery API.
 *
 * Decision D-9. The blueprint's §11 called the Delivery API "Public/scoped API
 * key" while the build brief called it "public, read-only, cached" — contradictory.
 * Resolution: public by default, with the key infrastructure present so a site can
 * lock it down without re-engineering.
 *
 * Requirements 8.3, 8.6.
 */
class AuthenticateDeliveryApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('cms.api.delivery.require_key', false)) {
            return $next($request);
        }

        $expected = (string) config('cms.api.delivery.key', '');

        /*
         * Enforcement is switched on but no key is configured. Failing closed is
         * the only safe reading: treating a blank expected key as "allow" would
         * mean a typo'd env var silently reopens an API the operator believes is
         * locked. 503 rather than 401, because the fault is server configuration.
         */
        if ($expected === '') {
            return response()->json([
                'message' => 'Delivery API key enforcement is enabled but no key is configured.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $provided = $this->providedKey($request);

        // hash_equals, not ===, so the comparison does not leak the key's length
        // or its matching prefix through response timing.
        if ($provided === null || ! hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Invalid or missing API key.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $response = $next($request);

        /*
         * With a key required, responses vary per key, so a shared cache must not
         * reuse one client's body for another's request.
         */
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function providedKey(Request $request): ?string
    {
        $header = $request->header('X-API-Key');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        // Bearer is accepted too, since API clients reach for it by habit.
        $bearer = $request->bearerToken();

        return is_string($bearer) && $bearer !== '' ? $bearer : null;
    }
}
