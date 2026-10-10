<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

class ForgotPasswordController extends Controller
{
    /**
     * Request a password reset link.
     *
     * Always the same 200 message, so the response never reveals whether the email belongs to a
     * customer, whether the customer has a password, whether the broker's per-account cooldown
     * suppressed the email or whether queueing it failed. See docs/api/authentication.md.
     */
    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            // Laravel's broker (timeboxed): looks the customer up, applies the cooldown, stores a
            // hashed token and queues ResetPasswordNotification. The query condition makes
            // social-only customers (no password) indistinguishable from unknown emails, so they
            // get neither a token nor an email. (The user provider ignores credential keys
            // containing "password", hence the key name.)
            Password::broker()->sendResetLink([
                'email' => $request->validated('email'),
                'eligible' => fn (Builder $query) => $query->whereNotNull('password'),
            ]);
        } catch (Throwable $e) {
            // Only the exception class: transport messages may contain the address, and the
            // token must never reach the log.
            Log::error('Password reset email could not be queued.', ['exception' => $e::class]);
        }

        return response()->json(['message' => __('passwords.link_requested')]);
    }
}
