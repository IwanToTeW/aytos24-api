<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\V1\CustomerResource;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Sign in with email and password.
     *
     * Wrong passwords and unknown emails get the same 422 (and take the same time, see
     * SessionGuard's timebox). Browser (stateful) requests get a regenerated session; requests
     * without one are only validated, as native token issuance is not implemented. See
     * docs/api/authentication.md.
     */
    public function __invoke(LoginRequest $request): CustomerResource
    {
        $key = $request->throttleKey();

        if (RateLimiter::tooManyAttempts($key, config('auth.rate_limits.login_failures_per_minute'))) {
            event(new Lockout($request));

            throw new ThrottleRequestsException('Too Many Attempts.', headers: ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        $guard = Auth::guard('web');

        if ($request->hasSession() && $guard->check()) {
            $guard->logoutCurrentDevice();
        }

        $authenticated = $request->hasSession()
            ? $guard->attempt($request->credentials(), $request->remember())
            : $guard->validate($request->credentials());

        if (! $authenticated) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return new CustomerResource($guard->getLastAttempted());
    }
}
