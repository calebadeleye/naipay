<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a one-time verification code, as an alternative to an authenticator
 * app, for a pending sign-in.
 *
 * Deliberately not queued: this stands in for a code the operator is waiting
 * on right now, in a five-minute challenge window, so it has to leave
 * immediately rather than wait for a queue worker to pick it up.
 */
final class TwoFactorEmailCodeNotification extends Notification
{
    public function __construct(
        private readonly string $code,
        private readonly int $expiresInMinutes,
    ) {}

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
            ->subject("Your Every Merchant verification code is {$this->code}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line('Use this code to finish signing in to Every Merchant:')
            ->line("## {$this->code}")
            ->line("This code expires in {$this->expiresInMinutes} minutes and can be used once.")
            ->line('If you did not try to sign in, change your password and tell your administrator.')
            ->salutation('Every Merchant');
    }
}
