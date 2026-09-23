<?php

declare(strict_types=1);

use App\Http\Controllers\PreviewController;
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
