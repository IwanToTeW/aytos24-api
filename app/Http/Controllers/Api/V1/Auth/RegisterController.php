<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterCustomerRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegisterController extends Controller
{
    /**
     * Register a customer.
     *
     * Creates an unverified customer and queues the verification email. Nobody is signed in: the
     * customer signs in after verifying the email (verification-first policy). See
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

        return response()->json([
            'message' => __('auth.registered'),
            'verification_required' => true,
        ], 201);
    }
}
