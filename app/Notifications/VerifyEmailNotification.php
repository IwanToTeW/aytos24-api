<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * The email verification link, in the customer's language (lang/{bg,en}/notifications.php).
 * Queued, so registration never waits for, or fails because of, the mail transport; failed
 * deliveries are logged by the queue worker and kept in failed_jobs.
 */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
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

    public function __construct()
    {
        $this->afterCommit();
    }

    /**
     * A signed link to GET /api/v1/auth/verify-email/{id}/{hash}, valid for auth.verification.expire minutes.
     *
     * @param  mixed  $notifiable
     */
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'api.v1.auth.verify-email',
            now()->addMinutes(Config::integer('auth.verification.expire')),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }

    /**
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.verify_email.subject'))
            ->greeting(__('notifications.verify_email.greeting'))
            ->line(__('notifications.verify_email.intro'))
            ->line(__('notifications.verify_email.reason'))
            ->action(__('notifications.verify_email.action'), $url)
            ->line(trans_choice('notifications.verify_email.expires', $this->expiresInHours(), ['hours' => $this->expiresInHours()]))
            ->line(__('notifications.verify_email.ignore'))
            ->salutation(__('notifications.verify_email.salutation'));
    }

    private function expiresInHours(): int
    {
        return max(1, intdiv(Config::integer('auth.verification.expire'), 60));
    }
}
