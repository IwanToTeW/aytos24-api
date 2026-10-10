<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Body of POST /api/v1/auth/reset-password. The email is trimmed and lowercased as at
 * registration; the token and passwords are used as sent. The new password must meet the
 * registration password policy.
 */
class ResetPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower($this->input('email'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * What the password broker needs: the email and token to check, and the new password.
     *
     * @return array{email: string, token: string, password: string}
     */
    public function credentials(): array
    {
        return $this->safe()->only(['email', 'token', 'password']);
    }
}
