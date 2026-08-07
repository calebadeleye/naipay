<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Notifications;

use App\Domains\LoanApplications\Models\LoanApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LoanApplicationApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly LoanApplication $application,
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
            ->subject("Loan application {$this->application->application_number} approved")
            ->greeting("Hello {$notifiable->fullName()},")
            ->line("Your loan application has been approved for {$this->application->approved_amount?->format()}.")
            ->line('Your loan officer will be in touch to arrange disbursement.')
            ->salutation('Every Merchant');
    }
}
