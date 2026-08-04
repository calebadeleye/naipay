<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms that a staff password has changed.
 *
 * Sent unconditionally, including when the operator changed it themselves.
 * The value is in the case where they did not: this is how an account takeover
 * gets noticed.
 */
final class PasswordChangedNotification extends Notification implements ShouldQueue
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
            ->subject('Your Naipay password was changed')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('The password for your Naipay account was changed just now.')
            ->line('All other active sessions have been signed out.')
            ->line('If you did not make this change, contact your administrator immediately — your account may be compromised.')
            ->salutation('Naipay');
    }
}
