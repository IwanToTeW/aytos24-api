<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The password reset link, in the customer's language (lang/{bg,en}/notifications.php). Queued
 * like the verification email; the job is encrypted because it carries the plain reset token,
 * which must not sit readable in the jobs or failed_jobs tables.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * Retry transient mail transport failures.
     */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function __construct(#[\SensitiveParameter] string $token)
    {
        parent::__construct($token);

        $this->afterCommit();
    }

    /**
     * A link to the web app's reset page: {FRONTEND_URL}/reset-password?token=…&email=…
     *
     * @param  mixed  $notifiable
     */
    protected function resetUrl($notifiable): string
    {
        $frontend = Config::string('app.frontend_url');

        if (app()->isProduction() && ! str_starts_with($frontend, 'https://')) {
            throw new RuntimeException('FRONTEND_URL must use HTTPS in production.');
        }

        return $frontend.'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    /**
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = Config::integer('auth.passwords.'.Config::string('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject(__('notifications.reset_password.subject'))
            ->greeting(__('notifications.reset_password.greeting'))
            ->line(__('notifications.reset_password.reason'))
            ->action(__('notifications.reset_password.action'), $url)
            ->line(trans_choice('notifications.reset_password.expires', $minutes, ['minutes' => $minutes]))
            ->line(__('notifications.reset_password.ignore'))
            ->salutation(__('notifications.reset_password.salutation'));
    }

    /**
     * Called by the queue worker once every attempt has failed. Logs no address, token or link.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Password reset email delivery failed.', ['exception' => $e::class]);
    }
}
