<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Notifications;

use App\Domains\Receipts\Models\Receipt;
use App\Domains\Repayments\Models\Repayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a merchant their receipt once a repayment is approved.
 */
final class RepaymentReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Repayment $repayment,
        private readonly Receipt $receipt,
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
            ->subject("Receipt {$this->receipt->receipt_number} — payment received")
            ->greeting("Hello {$notifiable->fullName()},")
            ->line("We have received your payment of {$this->repayment->amount->format()} on {$this->repayment->payment_date->toFormattedDateString()}.")
            ->line("Receipt number: {$this->receipt->receipt_number}")
            ->line("Reference: {$this->repayment->repayment_reference}")
            ->salutation('Naipay');
    }
}
