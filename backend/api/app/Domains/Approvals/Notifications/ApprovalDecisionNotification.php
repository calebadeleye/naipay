<?php

declare(strict_types=1);

namespace App\Domains\Approvals\Notifications;

use App\Domains\Notifications\Channels\InAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the maker of a maker-checker record what happened to it.
 *
 * One generic notification for every enforced operation
 * (`repayment.approve`, `loan.write_off`, `merchant.approve`, ...) rather
 * than a class per operation — the content is the same shape regardless of
 * which domain raised it: what was decided, by whom, why (if rejected), and
 * where to go look.
 */
final class ApprovalDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * `$actionUrl` is a path relative to the admin console (e.g.
     * `/repayments/123`) — the one thing both delivery channels need but for
     * different reasons: the in-app bell hands it straight to the frontend
     * router, while the email link needs it turned into an absolute URL
     * against `config('app.admin_url')`.
     */
    public function __construct(
        private readonly string $operation,
        private readonly string $subjectReference,
        private readonly string $decision,
        private readonly string $actorName,
        private readonly string $actionUrl,
        private readonly ?string $reason = null,
        private readonly ?string $subjectType = null,
        private readonly ?int $subjectId = null,
    ) {
        $this->onQueue(config('naipay.queues.high', 'naipay-high'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', InAppChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $decisionLabel = ucfirst($this->decision);

        $message = (new MailMessage)
            ->subject("{$this->subjectReference} {$decisionLabel}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("{$this->actorName} has {$this->decision} {$this->subjectReference}.");

        if ($this->reason !== null) {
            $message->line("Reason: {$this->reason}");
        }

        $absoluteUrl = rtrim((string) config('app.admin_url'), '/').'/'.ltrim($this->actionUrl, '/');

        return $message
            ->action('View', $absoluteUrl)
            ->salutation('Every Merchant');
    }

    /**
     * @return array<string, mixed>
     */
    public function toInApp(object $notifiable): array
    {
        $decisionLabel = ucfirst($this->decision);

        return [
            'type' => $this->operation,
            'title' => "{$this->subjectReference} {$decisionLabel}",
            'body' => $this->reason ?? "{$this->actorName} has {$this->decision} {$this->subjectReference}.",
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'action_url' => $this->actionUrl,
        ];
    }
}
