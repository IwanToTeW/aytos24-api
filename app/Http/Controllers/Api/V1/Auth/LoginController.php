<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\V1\CustomerResource;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Sign in with email and password.
     *
     * Wrong passwords and unknown emails get the same 422 (and take the same time, see
     * SessionGuard's timebox). Correct credentials of a customer whose email is not verified get
     * 403 with code EMAIL_NOT_VERIFIED and no session (verification-first policy). Browser
     * (stateful) requests of verified customers get a regenerated session; requests without one are
     * only validated, as native token issuance is not implemented. See docs/api/authentication.md.
     */
    public function __invoke(LoginRequest $request): CustomerResource|JsonResponse
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

        // Only called once the password is known to be correct, so an unverified status is never
        // revealed to someone who does not know the password.
        $unverified = false;
        $isVerified = function (User $customer) use (&$unverified): bool {
            $unverified = ! $customer->hasVerifiedEmail();

            return ! $unverified;
        };

        $authenticated = $request->hasSession()
            ? $guard->attemptWhen($request->credentials(), $isVerified, $request->remember())
            : $guard->validate($request->credentials()) && $isVerified($guard->getLastAttempted());

        if ($unverified) {
            RateLimiter::clear($key);

            return response()->json([
                'message' => __('auth.unverified'),
                'code' => 'EMAIL_NOT_VERIFIED',
            ], 403);
        }

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
