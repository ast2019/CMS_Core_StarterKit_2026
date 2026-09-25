<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Delivery\CategoryController;
use App\Http\Controllers\Api\V1\Delivery\ContactController;
use App\Http\Controllers\Api\V1\Delivery\ContentController;
use App\Http\Controllers\Api\V1\Delivery\GalleryController;
use App\Http\Controllers\Api\V1\Delivery\PageController;
use App\Http\Controllers\Api\V1\Delivery\SearchController;
use App\Http\Controllers\Api\V1\Delivery\SeoController;
use App\Http\Controllers\Api\V1\Delivery\SiteController;
use App\Http\Controllers\Api\V1\Management\ManagementContentController;
use App\Http\Controllers\Api\V1\Management\TranslationReviewController;
use App\Http\Middleware\AuthenticateDeliveryApi;
use App\Http\Middleware\EnsureMaintenanceModeAllowsDelivery;
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
    | Only GET routes are registered here apart from the contact form, so
    | "read-only" is a property of the route table rather than a convention a
    | future controller might break. An architecture test asserts it.
    |
    */
    Route::middleware([
        'throttle:cms-delivery',
        AuthenticateDeliveryApi::class,
        EnsureMaintenanceModeAllowsDelivery::class,
        ResolveApiLocale::class,
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

        Route::get('categories/{slug}', [CategoryController::class, 'show'])
            ->where('slug', '[^/]+')
            ->name('api.v1.categories.show');

        Route::get('galleries/{slug}', [GalleryController::class, 'show'])
            ->where('slug', '[^/]+')
            ->name('api.v1.galleries.show');

        Route::get('search', SearchController::class)->name('api.v1.search');

        Route::get('slides', [SiteController::class, 'slides'])->name('api.v1.slides');
        Route::get('menus/{key}', [SiteController::class, 'menu'])
            ->whereAlphaNumeric('key')
            ->name('api.v1.menu');
        Route::get('settings', [SiteController::class, 'settings'])->name('api.v1.settings');
        Route::get('contact', [SiteController::class, 'contact'])->name('api.v1.contact');
        Route::get('not-found-page', [SiteController::class, 'notFoundPage'])->name('api.v1.not-found');
    });

    /*
     * The contact form is the one public write. It gets its own, much tighter
     * throttle: the read endpoints allow 120/min, which would be an invitation to
     * flood the submissions table.
     */
    Route::middleware([
        'throttle:cms-contact',
        AuthenticateDeliveryApi::class,
        EnsureMaintenanceModeAllowsDelivery::class,
        ResolveApiLocale::class,
    ])->post('contact', [ContactController::class, 'store'])->name('api.v1.contact.store');

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
