<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResetPasswordController extends Controller
{
    /**
     * Set a new password with the token from the reset email.
     *
     * Laravel's broker checks that the token belongs to the email and has not expired, and
     * deletes it after the reset. Unknown emails, wrong, expired and used tokens all get the same
     * 422 on `token`. The customer is not signed in; other browser sessions end on their next
     * request (Sanctum's AuthenticateSession). See docs/api/authentication.md.
     */
    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset($request->credentials(), function (User $customer, string $password) {
            // Hashed by the model's "hashed" cast; the new remember token signs out remember-me cookies.
            $customer->forceFill(['password' => $password])->setRememberToken(Str::random(60));
            $customer->save();

            event(new PasswordReset($customer));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => __('passwords.token')]);
        }

        return response()->json(['message' => __('passwords.reset')]);
    }
}
