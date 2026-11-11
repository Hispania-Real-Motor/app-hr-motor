<?php

use App\Http\Controllers\InternalGoogleReviewController;
use App\Http\Controllers\InternalEnreachErrorEventController;
use App\Http\Controllers\InternalMotorflashCommercialErrorController;
use Illuminate\Support\Facades\Route;

Route::middleware('internal.basic_auth')
    ->prefix('internal/google-reviews')
    ->group(function (): void {
        Route::get('/count', [InternalGoogleReviewController::class, 'count'])
            ->name('internal.google-reviews.count');
        Route::get('/reviews', [InternalGoogleReviewController::class, 'reviews'])
            ->name('internal.google-reviews.reviews');
    });

Route::middleware('enreach.events.secret')
    ->prefix('internal/enreach')
    ->group(function (): void {
        Route::post('/errors', [InternalEnreachErrorEventController::class, 'store'])
            ->name('internal.enreach.errors.store');
    });

Route::middleware('motorflash.commercial_errors.secret')
    ->prefix('internal/motorflash')
    ->group(function (): void {
        Route::post('/commercial-errors', [InternalMotorflashCommercialErrorController::class, 'store'])
            ->name('internal.motorflash.commercial-errors.store');
    });
