<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms that a merchant's portal password was set or changed.
 *
 * Sent unconditionally, including for first-time activation. The value is in
 * the case the merchant didn't do this themselves: this is how an account
 * takeover gets noticed.
 */
final class MerchantPasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
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
            ->subject('Your Every Merchant portal password was changed')
            ->greeting("Hello {$notifiable->fullName()},")
            ->line('The password for your Every Merchant portal account was set or changed just now.')
            ->line('All other active sessions have been signed out.')
            ->line('If you did not make this change, contact Every Merchant immediately — your account may be compromised.')
            ->salutation('Every Merchant');
    }
}
