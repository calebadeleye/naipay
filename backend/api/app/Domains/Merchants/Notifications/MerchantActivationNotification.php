<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invites a merchant to set their first password and activate their portal
 * account.
 *
 * Queued so a slow mail server never holds up the request — and so the
 * response time of the activation-request endpoint reveals nothing about
 * whether the address matched an account.
 */
final class MerchantActivationNotification extends Notification implements ShouldQueue
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
        // The link points at the standalone merchant portal, which posts the
        // token back to the API. The API itself never renders HTML.
        $url = rtrim((string) config('app.merchant_url'), '/')
            .'/activate?token='.urlencode($this->token)
            .'&email='.urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Activate your Every Merchant account')
            ->greeting("Hello {$notifiable->fullName()},")
            ->line('Your Every Merchant account is ready. Set a password to sign in and view your loans, apply for credit, and download your receipts.')
            ->action('Activate account', $url)
            ->line("This link expires in {$this->expiresInMinutes} minutes and can be used once.")
            ->line('If you were not expecting this email, no action is needed.')
            ->salutation('Every Merchant');
    }
}
