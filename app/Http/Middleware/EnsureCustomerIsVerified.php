<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verification-first policy: only customers with a verified email are signed in. Login refuses
 * unverified customers, so an authenticated request from one can only come from a session started
 * before the policy (or a token issued elsewhere). That session is ended and the request answered like
 * any signed-out one: 401 {"message": "Unauthenticated."}. Runs after auth:sanctum.
 */
class EnsureCustomerIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user();

        if ($customer instanceof MustVerifyEmail && ! $customer->hasVerifiedEmail()) {
            if ($request->hasSession()) {
                Auth::guard('web')->logoutCurrentDevice();

                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            throw new AuthenticationException;
        }

        return $next($request);
    }
}
