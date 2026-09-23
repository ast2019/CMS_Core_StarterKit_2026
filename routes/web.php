<?php

declare(strict_types=1);

use App\Http\Controllers\PreviewController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| This Core is headless: the public site is a separate frontend consuming the
| Delivery API (blueprint §1). So there are deliberately no page-rendering
| routes here beyond the draft preview endpoint.
|
*/

Route::get('/', fn () => redirect(config('cms.brand.panel_path', 'admin')))
    ->name('home');

/*
 * Draft preview. Requirements 4.6, 4.7.
 *
 * The `signed` middleware rejects a tampered or expired signature with a 403
 * before the controller runs, which is what makes Requirement 4.7 enforced by
 * the framework rather than by hand-rolled checks.
 */
Route::get('/preview/{type}/{id}/{locale}', PreviewController::class)
    ->middleware('signed')
    ->whereIn('type', ['content', 'page', 'gallery'])
    ->whereNumber('id')
    ->whereAlpha('locale')
    ->name('cms.preview');

/*
 * Sitemap suite. Requirement 7.4.
 *
 * At the root rather than under /api, because that is where crawlers and Search
 * Console look for them, and because these are XML documents for machines rather
 * than JSON for the frontend.
 */
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('cms.sitemap.index');
Route::get('/sitemap-images.xml', [SitemapController::class, 'images'])->name('cms.sitemap.images');
Route::get('/sitemap-videos.xml', [SitemapController::class, 'videos'])->name('cms.sitemap.videos');

/*
 * Declared AFTER the image/video routes: 'sitemap-images' would otherwise match
 * {locale} and shadow them, returning an empty locale sitemap instead. The locale
 * constraint also enforces it, but ordering makes the intent obvious.
 */
Route::get('/sitemap-{locale}.xml', [SitemapController::class, 'locale'])
    ->whereIn('locale', (array) config('cms.locales.supported', ['fa']))
    ->name('cms.sitemap.locale');
