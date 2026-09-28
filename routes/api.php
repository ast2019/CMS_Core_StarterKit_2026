<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Delivery\CategoryController;
use App\Http\Controllers\Api\V1\Delivery\ContactController;
use App\Http\Controllers\Api\V1\Delivery\ContentController;
use App\Http\Controllers\Api\V1\Delivery\FormController;
use App\Http\Controllers\Api\V1\Delivery\GalleryController;
use App\Http\Controllers\Api\V1\Delivery\PageController;
use App\Http\Controllers\Api\V1\Delivery\RedirectController;
use App\Http\Controllers\Api\V1\Delivery\SearchController;
use App\Http\Controllers\Api\V1\Delivery\SeoController;
use App\Http\Controllers\Api\V1\Delivery\SiteController;
use App\Http\Controllers\Api\V1\Management\ManagementContentController;
use App\Http\Controllers\Api\V1\Management\TranslationReviewController;
use App\Http\Middleware\AddDeliveryCacheValidators;
use App\Http\Middleware\AuthenticateDeliveryApi;
use App\Http\Middleware\EnsureMaintenanceModeAllowsDelivery;
use App\Http\Middleware\NormaliseNumericQuery;
use App\Http\Middleware\ResolveApiLocale;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Requirement 8.1 — everything is versioned under /api/v1/.
| Requirement 8.2 — the Delivery and Management APIs are separate route groups
| with separate guards, so an authorisation mistake in one cannot widen the other.
|
*/

Route::prefix('v1')->group(function (): void {

    /*
    |----------------------------------------------------------------------
    | Delivery API — public, read-only, cached
    |----------------------------------------------------------------------
    |
    | Requirements 8.3, 8.4, 8.7. Decision D-9: public by default, with optional
    | API-key enforcement available per site.
    |
    | Only GET routes are registered here apart from the form submissions, so
    | "read-only" is a property of the route table rather than a convention a
    | future controller might break. An architecture test asserts it.
    |
    */
    Route::middleware([
        'throttle:cms-delivery',
        AuthenticateDeliveryApi::class,
        EnsureMaintenanceModeAllowsDelivery::class,
        ResolveApiLocale::class,
        NormaliseNumericQuery::class,

        /*
         * INNERMOST, on purpose. Response middleware runs outward, so
         * AuthenticateDeliveryApi gets the last word on Cache-Control and its
         * `private, no-store` correctly overrides the public caching added here when
         * key enforcement is on — responses then vary per client and must not sit in a
         * shared cache. ResolveApiLocale likewise adds Vary/Content-Language after,
         * which a 304 is allowed to carry.
         */
        AddDeliveryCacheValidators::class,
    ])->group(function (): void {

        Route::get('news', [ContentController::class, 'index'])->name('api.v1.news.index');
        Route::get('news/{slug}', [ContentController::class, 'show'])
            ->where('slug', '[^/]+')
            ->name('api.v1.news.show');

        Route::get('news/{slug}/seo', [SeoController::class, 'forArticle'])
            ->where('slug', '[^/]+')
            ->name('api.v1.news.seo');

        /*
         * Pages, categories and galleries.
         *
         * These three are linkable menu targets AND sitemap entries, so the API was
         * already handing frontends URLs — /fa/about, /fa/category-slug,
         * /fa/gallery-slug — that nothing here could resolve. A header menu built in
         * the panel therefore produced links the frontend had to hardcode or drop.
         *
         * `[^/]+` on the slug, like news: a slug is per-locale and may be a Persian
         * or Arabic string, so the alpha-numeric constraints used for the menu key
         * would reject most real slugs.
         *
         * Each controller gates itself on its module toggle (Requirement 1.1). That
         * is done in the controller rather than by omitting the route, so the
         * OpenAPI document describes the same surface on every deployment and a
         * disabled module answers 404 instead of vanishing from the spec.
         */
        Route::get('pages/{slug}', [PageController::class, 'show'])
            ->where('slug', '[^/]+')
            ->name('api.v1.pages.show');

        Route::get('pages/{slug}/seo', [SeoController::class, 'forPage'])
            ->where('slug', '[^/]+')
            ->name('api.v1.pages.seo');

        Route::get('categories/{slug}', [CategoryController::class, 'show'])
            ->where('slug', '[^/]+')
            ->name('api.v1.categories.show');

        Route::get('categories/{slug}/seo', [SeoController::class, 'forCategory'])
            ->where('slug', '[^/]+')
            ->name('api.v1.categories.seo');

        Route::get('galleries/{slug}', [GalleryController::class, 'show'])
            ->where('slug', '[^/]+')
            ->name('api.v1.galleries.show');

        Route::get('galleries/{slug}/seo', [SeoController::class, 'forGallery'])
            ->where('slug', '[^/]+')
            ->name('api.v1.galleries.seo');

        Route::get('search', SearchController::class)->name('api.v1.search');

        Route::get('slides', [SiteController::class, 'slides'])->name('api.v1.slides');

        /*
         * The location key allows `-` and `_` as well as alphanumerics, because
         * `cms.menus.locations` is a per-deployment list and a client declaring
         * `utility-bar` or `mobile_drawer` would otherwise get a 404 from the ROUTER —
         * indistinguishable from the controller's "no such location" and impossible to
         * debug from the frontend. The controller validates the key against the
         * configured set, so the pattern only has to be permissive enough not to reject
         * a legitimate one first.
         */
        Route::get('menus/{key}', [SiteController::class, 'menu'])
            ->where('key', '[A-Za-z0-9_-]+')
            ->name('api.v1.menu');

        Route::get('settings', [SiteController::class, 'settings'])->name('api.v1.settings');
        Route::get('contact', [SiteController::class, 'contact'])->name('api.v1.contact');

        /*
         * Item 15 — a form built in the panel, as a schema to render. The key pattern matches
         * FormSchema::FORM_KEY_PATTERN, so a malformed key 404s at the router rather than
         * reaching a query.
         */
        Route::get('forms/{key}', [FormController::class, 'show'])
            ->where('key', '[a-z][a-z0-9-]*')
            ->name('api.v1.forms.show');
        Route::get('not-found-page', [SiteController::class, 'notFoundPage'])->name('api.v1.not-found');

        /*
         * The page rendered at the locale root. Served like `not-found-page` — by system
         * key rather than by slug — because that is what it is: a Page the application
         * resolves by name. A frontend had no way to ask which page belongs at /fa, so a
         * homepage was either hardcoded per client or not a CMS concept at all.
         */
        Route::get('home-page', [SiteController::class, 'homePage'])->name('api.v1.home-page');

        /*
         * The homepage's SEO payload, addressed by ROLE rather than by slug — a frontend
         * rendering /fa has no slug to ask with, which is the same reason home-page above
         * exists.
         */
        Route::get('home-page/seo', [SeoController::class, 'forHome'])->name('api.v1.home-page.seo');

        /*
         * The full redirect table, for a frontend that compiles redirects at build time.
         * The single-path lookup lives in its own throttle group below.
         */
        Route::get('redirects', [RedirectController::class, 'index'])->name('api.v1.redirects.index');
    });

    /*
     * Redirect resolution, with its own rate limiter.
     *
     * The frontend calls this on every 404 IT serves, from one server IP for the whole
     * site's traffic. Under the shared read limiter a crawler walking a few stale URLs
     * would exhaust the budget for every real visitor — and what breaks is redirect
     * handling, which is precisely the gap this endpoint closes. Same guards otherwise,
     * so it is still a public read-only Delivery route.
     */
    Route::middleware([
        'throttle:cms-redirects',
        AuthenticateDeliveryApi::class,
        EnsureMaintenanceModeAllowsDelivery::class,
        ResolveApiLocale::class,
    ])->get('redirects/resolve', [RedirectController::class, 'resolve'])
        ->name('api.v1.redirects.resolve');

    /*
     * The public writes — the contact form and, since item 15, submissions to any
     * form. They get their own, much tighter throttle: the read endpoints allow
     * 120/min, which would be an invitation to flood the submissions table.
     */
    Route::middleware([
        'throttle:cms-contact',
        AuthenticateDeliveryApi::class,
        EnsureMaintenanceModeAllowsDelivery::class,
        ResolveApiLocale::class,
    ])->group(function (): void {
        Route::post('contact', [ContactController::class, 'store'])->name('api.v1.contact.store');

        /*
         * Item 15 — submissions to any active form, under the SAME limiter. The limiter is keyed
         * by client IP alone, so the budget is shared across every form and the legacy endpoint:
         * a script cannot multiply its allowance by spreading itself over several forms.
         */
        Route::post('forms/{key}/submissions', [FormController::class, 'submit'])
            ->where('key', '[a-z][a-z0-9-]*')
            ->name('api.v1.forms.submissions.store');
    });

    /*
    |----------------------------------------------------------------------
    | Management API — authenticated, admin-scoped
    |----------------------------------------------------------------------
    |
    | Requirements 8.5, 8.6. Decision D-8: Sanctum only. The blueprint's §11 said
    | "Session/JWT" but §13's toolset lists no JWT package, and a third auth
    | mechanism is a third thing to secure. Filament uses the stateful session
    | guard; programmatic access uses Sanctum bearer tokens carrying the `manage`
    | ability.
    |
    */
    Route::prefix('manage')
        ->middleware([
            'throttle:cms-manage',
            'auth:sanctum',
            'abilities:'.config('cms.api.management.ability', 'manage'),
        ])
        ->group(function (): void {

            /*
             * ->parameters() is load-bearing, not cosmetic.
             *
             * apiResource('news', ...) generates a {news} route parameter, and
             * Laravel matches route parameters to controller arguments by NAME. With
             * methods typed `Content $content` the binding silently fails and Laravel
             * injects a brand-new empty Content instead — so $content->status is
             * null, no relations are loaded, and a policy check compares against a
             * null author_id. The symptoms are a 500 deep inside a resource and an
             * inexplicable 403, neither of which points at routing.
             */
            Route::apiResource('news', ManagementContentController::class)
                ->parameters(['news' => 'content'])
                ->names('api.v1.manage.news');

            Route::post('news/{content}/publish', [ManagementContentController::class, 'publish'])
                ->name('api.v1.manage.news.publish');
            Route::post('news/{content}/archive', [ManagementContentController::class, 'archive'])
                ->name('api.v1.manage.news.archive');

            Route::get('translations/pending', [TranslationReviewController::class, 'pending'])
                ->name('api.v1.manage.translations.pending');
            Route::post('translations/{content}/{locale}/review', [TranslationReviewController::class, 'review'])
                ->whereAlpha('locale')
                ->name('api.v1.manage.translations.review');
        });
});
