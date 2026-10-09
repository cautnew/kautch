<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

Route::get('/reset-password', [AdminController::class, 'resetPassword'])
    ->middleware('guest')
    ->name('password.reset.form');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
});

Route::fallback([SiteController::class, 'fallback'])->name('site.show');
