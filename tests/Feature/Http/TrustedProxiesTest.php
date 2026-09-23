<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * The application runs behind a reverse proxy in every containerised deployment
 * (docker-compose.yaml, Coolify, any ingress). These assert the consequences of
 * trusting it, rather than the configuration value itself — a test that read the config
 * back would pass even if the middleware were never applied.
 *
 * Both failures this guards against are silent. Nothing errors; the application simply
 * attributes every request to the proxy.
 */
beforeEach(function (): void {
    Route::get('/_proxy-probe', fn () => response()->json([
        'ip' => request()->ip(),
        'secure' => request()->isSecure(),
        'url' => request()->url(),
    ]));
});

it('reads the real client IP from the proxy instead of the proxy address', function (): void {
    /*
     * This is what the rate limiters key on. Untrusted, every visitor collapses into a
     * single bucket keyed by the proxy, so one busy consumer throttles the whole public
     * Delivery API — and the audit log names the proxy for every administrator (RULE #8).
     */
    $this->get('/_proxy-probe', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk()
        ->assertJsonPath('ip', '203.0.113.7');
});

it('treats a proxied HTTPS request as secure', function (): void {
    /*
     * TLS terminates at the proxy, so the request arrives over plain HTTP. Untrusted,
     * Laravel generates http:// URLs for a site served over https — mixed content, and
     * preview links that look wrong to the editor receiving them.
     */
    $this->get('/_proxy-probe', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'cms.example.com',
    ])
        ->assertOk()
        ->assertJsonPath('secure', true)
        ->assertJsonPath('url', 'https://cms.example.com/_proxy-probe');
});
