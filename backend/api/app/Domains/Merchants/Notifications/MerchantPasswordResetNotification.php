<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a password reset link to a merchant who has already activated their
 * portal account. See MerchantActivationNotification for a first-time setup.
 */
final class MerchantPasswordResetNotification extends Notification implements ShouldQueue
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
        $url = rtrim((string) config('app.merchant_url'), '/')
            .'/reset-password?token='.urlencode($this->token)
            .'&email='.urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Reset your Every Merchant password')
            ->greeting("Hello {$notifiable->fullName()},")
            ->line('We received a request to reset the password for your Every Merchant account.')
            ->action('Reset password', $url)
            ->line("This link expires in {$this->expiresInMinutes} minutes and can be used once.")
            ->line('If you did not request this, no action is needed — your password has not changed.')
            ->salutation('Every Merchant');
    }
}
