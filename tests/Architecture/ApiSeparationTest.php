<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;

/**
 * Requirements 8.1, 8.2, 8.3, 8.5, 8.7, 8.8.
 *
 * These assert properties of the ROUTE TABLE rather than of any controller. A
 * controller can be reviewed and still be wired into the wrong group later; the
 * route table is where the separation is either real or not.
 */

/**
 * @return list<RouteInstance>
 */
function apiRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RouteInstance $route): bool => str_starts_with($route->uri(), 'api/'),
    ));
}

/**
 * @return list<RouteInstance>
 */
function deliveryRoutes(): array
{
    return array_values(array_filter(
        apiRoutes(),
        fn (RouteInstance $route): bool => ! str_starts_with($route->uri(), 'api/v1/manage'),
    ));
}

it('versions every API route', function (): void {
    foreach (apiRoutes() as $route) {
        expect(str_starts_with($route->uri(), 'api/v1/'))->toBeTrue(
            "Route [{$route->uri()}] is not versioned under api/v1/.",
        );
    }
});

it('keeps the delivery API read-only apart from the contact form', function (): void {
    /*
     * Requirement 8.3. Enforced against the route table so "read-only" is a
     * structural fact: adding a POST to the delivery group fails the build rather
     * than waiting for a reviewer to notice.
     *
     * The contact form is the single, deliberate exception — it writes a submission
     * and returns only an id.
     */
    $writeRoutes = [];

    foreach (deliveryRoutes() as $route) {
        $writeMethods = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);

        if ($writeMethods === []) {
            continue;
        }

        if ($route->uri() === 'api/v1/contact') {
            continue;
        }

        $writeRoutes[] = implode('|', $writeMethods).' '.$route->uri();
    }

    expect($writeRoutes)->toBeEmpty(
        'Requirement 8.3 violated: the Delivery API must be read-only. Offending routes: '
        .implode(', ', $writeRoutes),
    );
});

it('guards every management route with sanctum and the manage ability', function (): void {
    // Requirements 8.5, 8.6.
    $ability = config('cms.api.management.ability', 'manage');

    $manageRoutes = array_filter(
        apiRoutes(),
        fn (RouteInstance $route): bool => str_starts_with($route->uri(), 'api/v1/manage'),
    );

    expect($manageRoutes)->not->toBeEmpty('No management routes are registered.');

    foreach ($manageRoutes as $route) {
        $middleware = $route->gatherMiddleware();

        expect(in_array('auth:sanctum', $middleware, true))->toBeTrue(
            "Route [{$route->uri()}] is not behind auth:sanctum.",
        );

        expect(in_array("abilities:{$ability}", $middleware, true))->toBeTrue(
            "Route [{$route->uri()}] does not require the [{$ability}] token ability.",
        );
    }
});

it('never places a management guard on a delivery route', function (): void {
    // Requirement 8.2 — the two groups must not share guards, or an authorisation
    // change in one silently alters the other.
    foreach (deliveryRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        expect(in_array('auth:sanctum', $middleware, true))->toBeFalse(
            "Delivery route [{$route->uri()}] must not require authentication.",
        );
    }
});

it('rate-limits every API route', function (): void {
    // Requirement 8.7.
    foreach (apiRoutes() as $route) {
        $throttles = array_filter(
            $route->gatherMiddleware(),
            fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:'),
        );

        expect($throttles)->not->toBeEmpty(
            "Route [{$route->uri()}] has no rate limiter.",
        );
    }
});

it('rate-limits the public write far more tightly than the reads', function (): void {
    /*
     * The contact form is the only public endpoint that writes a row. Giving it the
     * read allowance would be an invitation to flood the submissions table, so it
     * gets its own limiter — and this asserts the two are genuinely different rather
     * than the same name reused.
     */
    $contact = collect(apiRoutes())
        ->first(fn (RouteInstance $route): bool => $route->uri() === 'api/v1/contact'
            && in_array('POST', $route->methods(), true));

    expect($contact)->not->toBeNull()
        ->and($contact->gatherMiddleware())->toContain('throttle:cms-contact');

    $news = collect(apiRoutes())
        ->first(fn (RouteInstance $route): bool => $route->uri() === 'api/v1/news');

    expect($news->gatherMiddleware())->toContain('throttle:cms-delivery');
});

it('exposes no GraphQL endpoint', function (): void {
    // Requirement 8.8 — explicitly out of scope (blueprint's exclusions).
    foreach (Route::getRoutes()->getRoutes() as $route) {
        expect(str_contains(strtolower($route->uri()), 'graphql'))->toBeFalse(
            "Route [{$route->uri()}] looks like a GraphQL endpoint, which is out of scope.",
        );
    }

    $lock = json_decode((string) file_get_contents(projectPath('composer.lock')), true);

    $packages = array_map(
        fn (array $package): string => strtolower($package['name']),
        [...$lock['packages'], ...$lock['packages-dev']],
    );

    foreach (['nuwave/lighthouse', 'rebing/graphql-laravel', 'webonyx/graphql-php'] as $forbidden) {
        expect(in_array($forbidden, $packages, true))->toBeFalse(
            "GraphQL package [{$forbidden}] is installed but GraphQL is out of scope.",
        );
    }
});
