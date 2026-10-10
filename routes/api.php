<?php

use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\HelloController;
use App\Http\Controllers\Api\V1\TodayMealController;
use App\Http\Middleware\SetLocaleFromAcceptLanguage;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// API routes are prefixed with /api and documented by Scramble at /docs/api.

Route::prefix('v1')->group(function () {
    Route::get('hello', HelloController::class);
    Route::get('meals/today', TodayMealController::class)->name('api.v1.meals.today');

    // Customer authentication (docs/api/authentication.md). Requests from the web app
    // (SANCTUM_STATEFUL_DOMAINS) get a session and CSRF protection; food discovery stays stateless.
    Route::prefix('auth')
        ->name('api.v1.auth.')
        ->middleware([EnsureFrontendRequestsAreStateful::class, SetLocaleFromAcceptLanguage::class])
        ->group(function () {
            Route::post('register', RegisterController::class)
                ->middleware('throttle:register')
                ->name('register');

            Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
                ->middleware('throttle:verify-email')
                ->name('verify-email');

            Route::post('email/verification-notification', EmailVerificationNotificationController::class)
                ->middleware(['auth:sanctum', 'throttle:verification-notification'])
                ->name('verification-notification');
        });
});
