<?php

declare(strict_types=1);

namespace App\Domains\Loans\Notifications;

use App\Domains\Loans\Models\Loan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a merchant once their loan has been disbursed.
 */
final class LoanDisbursedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Loan $loan,
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
            ->subject("Loan {$this->loan->loan_reference} disbursed")
            ->greeting("Hello {$notifiable->fullName()},")
            ->line("Your loan of {$this->loan->principal_amount->format()} has been disbursed.")
            ->line("First repayment date: {$this->loan->first_repayment_date?->toFormattedDateString()}")
            ->line("Maturity date: {$this->loan->maturity_date?->toFormattedDateString()}")
            ->salutation('Every Merchant');
    }
}
