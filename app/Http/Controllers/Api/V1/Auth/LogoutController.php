<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    /**
     * Sign out of the current browser session only.
     *
     * Invalidates the session, issues a new CSRF token and expires the remember-me cookie. The
     * remember token is not cycled, so the customer's other browsers stay signed in.
     */
    public function __invoke(Request $request): Response
    {
        if ($request->hasSession()) {
            Auth::guard('web')->logoutCurrentDevice();

            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
