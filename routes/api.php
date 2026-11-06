<?php

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\LayoutController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\PageVersionController;
use App\Http\Controllers\Api\V1\PieceController;
use App\Http\Controllers\Api\V1\RouteController;
use App\Http\Controllers\Api\V1\WebsiteController;
use App\Http\Controllers\Api\V1\WebsiteUserController;
use App\Http\Middleware\ResolvePageVersion;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    // Public content-delivery endpoint — no auth:sanctum, never exposes draft/unpublished content.
    Route::get('content/{website}/{slug}', [ContentController::class, 'show'])
        ->name('content.show');

    // Public URL-routing resolution — a live site's frontend needs to resolve
    // an arbitrary visited path before it knows anything else about it.
    // Registered ahead of the authenticated websites.routes resource below so
    // "resolve" is never swallowed by that resource's {route} wildcard.
    Route::get('websites/{website}/routes/resolve', [RouteController::class, 'resolve'])
        ->name('websites.routes.resolve');

    Route::middleware('auth:sanctum')->group(function () {
        Route::apiResource('websites', WebsiteController::class);

        Route::apiResource('websites.users', WebsiteUserController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::apiResource('websites.layouts', LayoutController::class);

        Route::apiResource('websites.assets', AssetController::class);

        Route::get('websites/{website}/assets/{asset}/download', [AssetController::class, 'download'])
            ->name('websites.assets.download');

        Route::apiResource('websites.pages', PageController::class);

        Route::apiResource('websites.routes', RouteController::class);

        Route::get('pages/{page}/versions', [PageVersionController::class, 'index'])
            ->name('pages.versions.index');
        Route::post('pages/{page}/versions', [PageVersionController::class, 'store'])
            ->name('pages.versions.store');

        Route::prefix('pages/{page}')->scopeBindings()->middleware(ResolvePageVersion::class)->group(function () {
            foreach ([
                '' => 'pages.current-version.',
                '{version}' => 'versions.'
            ] as $prefix => $name) {
                Route::prefix($prefix)->name($name)->whereUuid('version')->group(function () {
                    Route::get('/', [PageVersionController::class, 'show'])->name('show');
                    Route::put('/', [PageVersionController::class, 'update'])->name('update');
                    Route::delete('/', [PageVersionController::class, 'destroy'])->name('destroy');

                    Route::post('submit-review', [PageVersionController::class, 'submitReview'])->name('submit-review');
                    Route::post('approve', [PageVersionController::class, 'approve'])->name('approve');
                    Route::post('reject', [PageVersionController::class, 'reject'])->name('reject');
                    Route::post('publish', [PageVersionController::class, 'publish'])->name('publish');

                    Route::get('pieces', [PieceController::class, 'index'])->name('pieces.index');
                    Route::post('pieces', [PieceController::class, 'store'])->name('pieces.store');
                });
            }
        });

        Route::prefix('versions/{version}')->scopeBindings()->middleware(ResolvePageVersion::class)->group(function () {
            Route::get('/', [PageVersionController::class, 'show'])->name('show');
            Route::put('/', [PageVersionController::class, 'update'])->name('update');
            Route::delete('/', [PageVersionController::class, 'destroy'])->name('destroy');

            Route::post('submit-review', [PageVersionController::class, 'submitReview'])->name('submit-review');
            Route::post('approve', [PageVersionController::class, 'approve'])->name('approve');
            Route::post('reject', [PageVersionController::class, 'reject'])->name('reject');
            Route::post('publish', [PageVersionController::class, 'publish'])->name('publish');

            Route::get('pieces', [PieceController::class, 'index'])->name('pieces.index');
            Route::post('pieces', [PieceController::class, 'store'])->name('pieces.store');
        });

        Route::get('pieces/{piece}', [PieceController::class, 'show'])
            ->name('pieces.show');
        Route::put('pieces/{piece}', [PieceController::class, 'update'])
            ->name('pieces.update');
        Route::delete('pieces/{piece}', [PieceController::class, 'destroy'])
            ->name('pieces.destroy');
    });

    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('login');

        Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('password.email');

        Route::post('reset-password', [NewPasswordController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('password.reset');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
                ->name('logout');

            Route::get('profile', [ProfileController::class, 'show'])
                ->name('profile.show');

            Route::put('profile', [ProfileController::class, 'update'])
                ->name('profile.update');

            Route::put('change-password', [PasswordController::class, 'update'])
                ->name('password.update');
        });
    });
});
