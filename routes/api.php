<?php

use App\Http\Controllers\Api\V1\Auth\CurrentCustomerController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\HelloController;
use App\Http\Controllers\Api\V1\TodayMealController;
use App\Http\Middleware\EnsureCustomerIsVerified;
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

            Route::post('login', LoginController::class)
                ->middleware('throttle:login')
                ->name('login');

            Route::post('logout', LogoutController::class)
                ->middleware('auth:sanctum')
                ->name('logout');

            // Customer-only endpoints add EnsureCustomerIsVerified after auth:sanctum. Logout does
            // not, so a session from before the verification-first policy can still be ended.
            Route::get('user', CurrentCustomerController::class)
                ->middleware(['auth:sanctum', EnsureCustomerIsVerified::class])
                ->name('user');

            Route::post('forgot-password', ForgotPasswordController::class)
                ->middleware('throttle:forgot-password')
                ->name('forgot-password');

            Route::post('reset-password', ResetPasswordController::class)
                ->middleware('throttle:reset-password')
                ->name('reset-password');

            Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
                ->middleware('throttle:verify-email')
                ->name('verify-email');

            Route::post('email/verification-notification', EmailVerificationNotificationController::class)
                ->middleware('throttle:verification-notification')
                ->name('verification-notification');
        });
});
