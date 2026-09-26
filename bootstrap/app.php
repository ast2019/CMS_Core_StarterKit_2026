<?php

declare(strict_types=1);

use App\Http\Middleware\HandleRedirects;
use Illuminate\Console\Scheduling\Schedule;
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
        /*
         * Redirect engine (Requirement 7.5).
         *
         * GLOBAL, not prependToGroup('web'). A group's middleware only runs once a
         * route in that group matches — but a moved URL has no route by definition, so
         * a group-scoped redirect engine 404s on exactly the paths it exists to handle.
         * That was the first implementation and every redirect test failed with a 404.
         *
         * Being global means it also sees API and panel requests, which it must not
         * touch: redirecting an API consumer would turn a JSON response into an HTML
         * redirect body. HandleRedirects excludes those prefixes itself, so the
         * exclusion lives with the logic rather than in this registration.
         */
        $middleware->prepend(HandleRedirects::class);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        /*
         * Trust the reverse proxy's X-Forwarded-* headers.
         *
         * Required for any deployment behind a proxy, which includes the Docker/Coolify
         * Docker image. Two things break without it, both quietly:
         *
         *  - Every request appears to originate from the proxy, so the IP-keyed rate
         *    limiters (CMS_DELIVERY_RATE_LIMIT) put ALL visitors in one bucket: a single
         *    busy client can throttle the entire public API. The audit log records the
         *    proxy's address too, which undermines RULE #8 — an audit trail that names
         *    the same IP for every administrator answers no question worth asking.
         *
         *  - The request looks like HTTP even when the browser used HTTPS, because TLS
         *    terminates at the proxy. Generated URLs then come out http://, which means
         *    mixed-content warnings and preview links that look untrustworthy.
         *
         * Default '*' trusts any proxy. That is correct when the application is only
         * reachable THROUGH the proxy, which is how the container is deployed: it publishes
         * no host port, so nothing else can connect. If you expose it directly,
         * set TRUSTED_PROXIES to the proxy's address, because a client that can reach
         * the app itself could otherwise spoof its own IP and evade rate limits.
         */
        $proxies = trim((string) env('TRUSTED_PROXIES', '*'));

        $middleware->trustProxies(
            at: $proxies === '*' || $proxies === '' ? '*' : array_map('trim', explode(',', $proxies)),
        );
    })
    ->withSchedule(function (Schedule $schedule): void {
        /*
         * SCHEDULED PUBLISHING.
         *
         * The only task here that is load-bearing for correctness. A record with
         * status `published` and a future publish_date is already excluded by
         * HasPublishStatus::live() and already included once the clock passes it — but
         * the Delivery cache is invalidated by model WRITES (DeliveryCacheObserver),
         * and nothing is written at the moment an embargo elapses. Without this, "goes
         * live at 8am" meant "goes live whenever the cached payload happens to expire".
         *
         * Note what it does NOT fix: the sitemaps are regenerated per request and served
         * with `Cache-Control: max-age=3600`, so a crawler can hold a sitemap without
         * the new URL for up to an hour regardless. That is an HTTP cache, not a
         * server-side one, and no tag purge reaches it.
         *
         * Every minute, because the panel lets an editor pick a publish time to the
         * minute and a scheduler that ran hourly would make that precision a lie. The
         * task is three COUNT queries — `contents` and `galleries` carry a
         * (status, publish_date) index; `pages` does not, and is small enough not to
         * care — and it returns without touching the cache when nothing is due, which
         * is the overwhelmingly common case.
         *
         * withoutOverlapping(2): if a run is ever slow, a second must not start beside
         * it and invalidate the same tags again. The EXPIRY is the important argument.
         * Laravel's default is 1440 minutes, and the mutex is released on SIGTERM but
         * NOT on a SIGKILL, an OOM kill or a host failure — so one hard-killed run
         * would silence scheduled publishing for a whole day while `schedule:list`
         * still reported the task as registered. Two minutes bounds that to a single
         * skipped tick.
         *
         * onOneServer is deliberately NOT set: it needs a lock-capable cache driver and
         * the default store here is the database. A multi-server deployment should add
         * it.
         */
        $schedule->command('cms:publish-due')
            ->everyMinute()
            ->withoutOverlapping(2);

        /*
         * QUEUE HYGIENE. Both tables grow without bound otherwise: job_batches on
         * every batch, failed_jobs on every failure. Neither is content, and losing
         * old rows costs nothing once the failures in them have been read.
         */
        $schedule->command('queue:prune-batches --hours=48')->daily();
        $schedule->command('queue:prune-failed --hours=336')->weekly();

        /*
         * DELIBERATELY NOT SCHEDULED, so the absences are not mistaken for oversights:
         *
         *  - The AUDIT LOG is never pruned. RULE #8 makes it append-only with no
         *    opt-out, and a retention policy is exactly the opt-out it forbids. It
         *    grows, and that is the intended trade.
         *  - CONTENT VERSIONS need no task. HasContentVersions::pruneVersions() already
         *    runs inside the write that creates a version, so the cms.versions.keep
         *    ceiling is enforced continuously rather than nightly.
         *  - SITEMAPS are generated on demand and cached, not written to disk (RULE #9
         *    means local storage is not shared between containers), so there is nothing
         *    to regenerate on a timer.
         */
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
