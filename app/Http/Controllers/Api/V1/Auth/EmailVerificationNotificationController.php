<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ResendVerificationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Timebox;
use Throwable;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification link (guests: unverified customers cannot sign in).
     *
     * Always the same 200 message, so the response never reveals whether the email belongs to a
     * customer, is already verified, is within the per-account cooldown or whether queueing failed.
     * See docs/api/authentication.md.
     */
    public function __invoke(ResendVerificationRequest $request, Timebox $timebox): JsonResponse
    {
        // Same minimum duration as login and the password broker, so known and unknown emails take
        // the same time.
        $timebox->call(function () use ($request) {
            $customer = User::where('email', $request->validated('email'))->whereNull('email_verified_at')->first();

            if ($customer === null) {
                return;
            }

            // At most one email per account per cooldown, whoever asks: prevents mail flooding a
            // victim from many IP addresses. Silent, so it reveals nothing either.
            RateLimiter::attempt(
                'verification-email:'.$customer->getKey(),
                1,
                function () use ($customer) {
                    try {
                        $customer->sendEmailVerificationNotification();
                    } catch (Throwable $e) {
                        // Only the exception class: transport messages may contain the address.
                        Log::error('Verification email could not be queued.', ['exception' => $e::class]);
                    }
                },
                Config::integer('auth.verification.resend_cooldown'),
            );
        }, Config::integer('auth.timebox_duration'));

        return response()->json(['message' => __('auth.verification_link_requested')]);
    }
}
