<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Body of POST /api/v1/auth/register. Name and email are trimmed by the global request
 * middleware (passwords are not); the email is lowercased here before validation.
 */
class RegisterCustomerRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Only these attributes ever reach the customer record.
     *
     * @return array{name: string, email: string, password: string}
     */
    public function customerAttributes(): array
    {
        return $this->safe()->only(['name', 'email', 'password']);
    }

    public function remember(): bool
    {
        return $this->boolean('remember');
    }
}
