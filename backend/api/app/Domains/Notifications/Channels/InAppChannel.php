<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Channels;

use App\Domains\Notifications\Models\StaffNotification;
use Illuminate\Notifications\Notification;

/**
 * Delivers a notification to a staff member's in-app bell rather than an
 * external transport.
 *
 * Referenced directly by its class name from a notification's `via()` —
 * Laravel resolves any channel class out of the container the same way it
 * resolves the built-in `mail`/`database` channels, so nothing further needs
 * registering.
 */
final class InAppChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toInApp')) {
            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = $notification->toInApp($notifiable);

        StaffNotification::create([
            ...$payload,
            'staff_id' => $notifiable->getKey(),
        ]);
    }
}
