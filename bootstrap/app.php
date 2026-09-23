<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Sanctum ships these middleware but does NOT register the aliases — since
         * Laravel 11 that is the application's job. Without them, `abilities:manage`
         * on a route resolves as a class name and throws
         * "Target class [abilities] does not exist" at request time.
         *
         * That failure mode matters: a route intended to be ability-gated would be
         * broken rather than merely unguarded, so it fails loudly. But if the
         * middleware string were ever dropped instead, every valid Sanctum token
         * would reach the Management API regardless of its scope — which is why
         * tests/Architecture/ApiSeparationTest asserts the alias is present on every
         * management route.
         *
         * Requirements 8.5, 8.6.
         */
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
