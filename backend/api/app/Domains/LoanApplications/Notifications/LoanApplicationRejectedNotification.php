<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Notifications;

use App\Domains\LoanApplications\Models\LoanApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LoanApplicationRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly LoanApplication $application,
        private readonly string $reason,
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
            ->subject("Loan application {$this->application->application_number} update")
            ->greeting("Hello {$notifiable->fullName()},")
            ->line('We were unable to approve your loan application at this time.')
            ->line("Reason: {$this->reason}")
            ->line('Speak to your loan officer if you would like to discuss this further.')
            ->salutation('Every Merchant');
    }
}
