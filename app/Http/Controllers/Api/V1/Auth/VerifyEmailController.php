<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class VerifyEmailController extends Controller
{
    /**
     * Verify an email address from the signed link in the verification email.
     *
     * Works without a session (the link may be opened on another device) and never signs
     * anyone in. Always redirects to {FRONTEND_URL}/email-verified?status=<status>; the
     * destination is never taken from the request.
     */
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $status = $this->verify($request, $id, $hash);

        return redirect()->away(config('app.frontend_url').'/email-verified?status='.$status);
    }

    private function verify(Request $request, string $id, string $hash): string
    {
        // Check the signature before the expiry, so a forged link is never reported as merely expired.
        if (! URL::hasCorrectSignature($request)) {
            return 'invalid';
        }

        if (! URL::signatureHasNotExpired($request)) {
            return 'expired';
        }

        $customer = ctype_digit($id) ? User::find($id) : null;

        // The hash binds the link to the email it was sent to; a changed email invalidates it.
        if ($customer === null || ! hash_equals(sha1($customer->getEmailForVerification()), $hash)) {
            return 'invalid';
        }

        if ($customer->hasVerifiedEmail()) {
            return 'already-verified';
        }

        $customer->markEmailAsVerified();
        event(new Verified($customer));

        return 'verified';
    }
}
