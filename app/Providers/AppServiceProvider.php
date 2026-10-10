<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Public API: 60 requests per minute per client IP (documented in docs/api/openapi.yaml).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Authentication limits (config/auth.php, documented in docs/api/authentication.md).
        RateLimiter::for('register', fn (Request $request) => Limit::perHour(config('auth.rate_limits.register_per_hour'))
            ->by($request->ip()));
        // Login requests per IP; failed attempts per email + IP are limited in LoginController.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(config('auth.rate_limits.login_per_minute'))
            ->by($request->ip()));
        RateLimiter::for('verify-email', fn (Request $request) => Limit::perMinute(config('auth.rate_limits.verify_email_per_minute'))
            ->by($request->ip()));
        // Per IP; each account additionally gets at most one email per cooldown (controller).
        RateLimiter::for('verification-notification', fn (Request $request) => Limit::perMinute(config('auth.rate_limits.verification_notification_per_minute'))
            ->by($request->ip()));
        // Per IP only, never per email, so a 429 says nothing about whether an account exists.
        RateLimiter::for('forgot-password', fn (Request $request) => Limit::perHour(config('auth.rate_limits.forgot_password_per_hour'))
            ->by($request->ip()));
        RateLimiter::for('reset-password', fn (Request $request) => Limit::perMinute(config('auth.rate_limits.reset_password_per_minute'))
            ->by($request->ip()));
        RateLimiter::for('csrf-cookie', fn (Request $request) => Limit::perMinute(config('auth.rate_limits.csrf_cookie_per_minute'))
            ->by($request->ip()));

        // Customer password policy (registration and password reset).
        Password::defaults(fn () => Password::min(8)
            ->max(128)
            ->letters()
            ->numbers()
            ->when(app()->isProduction(), fn (Password $rule) => $rule->uncompromised()));
    }
}
