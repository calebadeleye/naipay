<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use App\Domains\Identity\Models\Staff;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Tells every Super Administrator that a staff member has signed in.
 *
 * A lightweight security signal, not an audit trail — LoginAttempt already
 * records every attempt for that. This exists so someone with the reach to
 * act is passively aware of sign-in activity without having to go looking
 * for it.
 *
 * "Location" here is the request's IP address, not a resolved place name:
 * true geolocation would mean sending that IP to a third-party lookup
 * service, which this does not do without that being a deliberate,
 * separately reviewed decision.
 */
final class StaffSignedInNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Staff $signedInStaff,
        private readonly Carbon $signedInAt,
        private readonly ?string $ipAddress,
        private readonly string $device,
    ) {
        $this->onQueue(config('naipay.queues.default', 'naipay-default'));
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
            ->subject("Naipay sign-in: {$this->signedInStaff->fullName()}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("{$this->signedInStaff->fullName()} ({$this->signedInStaff->email}) just signed in to Naipay.")
            ->line("Time: {$this->signedInAt->toDayDateTimeString()} UTC")
            ->line('IP address: '.($this->ipAddress ?? 'Unknown'))
            ->line("Device: {$this->device}")
            ->line('If this was not expected, review the account under Staff and consider disabling it.')
            ->salutation('Naipay');
    }
}
