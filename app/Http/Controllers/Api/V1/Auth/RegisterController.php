<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterCustomerRequest;
use App\Http\Resources\V1\CustomerResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegisterController extends Controller
{
    /**
     * Register a customer.
     *
     * Creates an unverified customer, queues the verification email and, for browser (stateful)
     * requests, signs the customer in with a regenerated session. Requests without a session
     * (future native clients) get the account but no session or token. See
     * docs/api/authentication.md.
     */
    public function __invoke(RegisterCustomerRequest $request): JsonResponse
    {
        try {
            $customer = User::create([...$request->customerAttributes(), 'locale' => app()->getLocale()]);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent registration of the same email.
            throw ValidationException::withMessages(['email' => __('validation.unique', ['attribute' => __('validation.attributes.email')])]);
        }

        try {
            event(new Registered($customer));
        } catch (Throwable $e) {
            // The account exists; the customer can request the email again. Logged, never shown.
            report($e);
        }

        if ($request->hasSession()) {
            $guard = Auth::guard('web');

            if ($guard->check()) {
                $guard->logout();
            }

            $guard->login($customer, $request->remember());
            $request->session()->regenerate();
        }

        return (new CustomerResource($customer->refresh()))->response()->setStatusCode(201);
    }
}
