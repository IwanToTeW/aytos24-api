<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of POST /api/v1/auth/login. The email is trimmed (global middleware) and lowercased like
 * at registration; the password is neither trimmed nor checked against the password policy.
 */
class LoginRequest extends FormRequest
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
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return $this->safe()->only(['email', 'password']);
    }

    public function remember(): bool
    {
        return $this->boolean('remember');
    }

    /**
     * Failed attempts are counted per email and IP address, whether or not the account exists.
     */
    public function throttleKey(): string
    {
        return 'login:'.sha1($this->validated('email')).'|'.$this->ip();
    }
}
