<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a password reset link to a staff member.
 *
 * Queued so a slow mail server never holds up the request — and so the
 * response time of the forgot-password endpoint reveals nothing about whether
 * the address matched an account.
 */
final class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly int $expiresInMinutes,
    ) {
        $this->onQueue(config('naipay.queues.high', 'naipay-high'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The link points at the administrative console, which posts the token
        // back to the API. The API itself never renders HTML.
        $url = rtrim((string) config('app.admin_url'), '/')
            .'/reset-password?token='.urlencode($this->token)
            .'&email='.urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Reset your Every Merchant password')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('We received a request to reset the password for your Every Merchant account.')
            ->action('Reset password', $url)
            ->line("This link expires in {$this->expiresInMinutes} minutes and can be used once.")
            ->line('If you did not request this, no action is needed — your password has not changed. Tell your administrator if you were not expecting this email.')
            ->salutation('Every Merchant');
    }
}
