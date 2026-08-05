<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Notifications;

use App\Domains\Merchants\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class MerchantApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Merchant $merchant,
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
        return (new MailMessage)
            ->subject('Welcome to Naipay — your account is approved')
            ->greeting("Hello {$notifiable->fullName()},")
            ->line("Your onboarding is complete and your merchant account ({$notifiable->merchant_number}) is now active.")
            ->line('You can now apply for a loan through your assigned loan officer.')
            ->salutation('Naipay');
    }
}
