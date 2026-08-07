<?php

declare(strict_types=1);

namespace App\Domains\Loans\Notifications;

use App\Domains\Loans\Models\Loan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a merchant their loan's repayment schedule as a PDF, on request
 * from an officer viewing the loan.
 *
 * The PDF is generated once, by the caller, and passed in as bytes rather
 * than regenerated here — this notification is only responsible for
 * delivery, matching the queued notification pattern used throughout the
 * app (`RepaymentReceiptNotification`, `LoanDisbursedNotification`), not the
 * document itself.
 *
 * The bytes are stored base64-encoded. A queued notification's payload is
 * JSON-encoded to reach the queue connection (Redis in every environment but
 * testing, which runs jobs synchronously and would not have caught this) —
 * raw binary PDF data is not valid UTF-8, and `json_encode` silently fails
 * on it, so this never actually reaches a queue driver that isn't `sync`.
 */
final class LoanScheduleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private readonly string $encodedPdf;

    public function __construct(
        private readonly Loan $loan,
        string $pdf,
        private readonly string $filename,
    ) {
        $this->encodedPdf = base64_encode($pdf);
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
            ->subject("Your repayment schedule — {$this->loan->loan_reference}")
            ->greeting("Hello {$notifiable->fullName()},")
            ->line("Attached is the repayment schedule for your loan {$this->loan->loan_reference}.")
            ->attachData(base64_decode($this->encodedPdf), $this->filename, ['mime' => 'application/pdf'])
            ->salutation('Every Merchant');
    }
}
