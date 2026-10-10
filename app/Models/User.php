<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A customer account (docs/api/authentication.md). Serialised for clients only through
 * App\Http\Resources\V1\CustomerResource.
 */
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Send the localised, queued verification email.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Send the localised, queued password reset email with a link to the web app.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Whether the customer can sign in with a password. Social-only customers (BE-008) cannot.
     */
    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * The language of emails sent to this customer, e.g. from the queue.
     */
    public function preferredLocale(): string
    {
        return $this->locale ?? config('app.locale');
    }
}
