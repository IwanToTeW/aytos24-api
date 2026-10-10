<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Resend the email verification link to the signed-in customer.
     *
     * Always 202 without a body; nothing is sent when the email is already verified. The
     * recipient is always the authenticated customer, never request input.
     */
    public function __invoke(Request $request): Response
    {
        $customer = $request->user();

        if (! $customer->hasVerifiedEmail()) {
            $customer->sendEmailVerificationNotification();
        }

        return response()->noContent(202);
    }
}
