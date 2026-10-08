<?php

use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

// Single public catch-all: resolves any path against the requesting Host's
// tenant website (routes table), falling back to the platform's own
// placeholder page for a host that isn't a managed website. A controller
// reference (not a closure), so route:cache works in production.
Route::get('/{path?}', [SiteController::class, 'show'])
    ->where('path', '.*')
    ->name('site.show');
