<?php

declare(strict_types=1);

namespace App\Domains\Reconciliation\Enums;

/**
 * A reconciliation exercise for one bank account over one statement period.
 *
 * Submission is refused while any line remains Unmatched — every line must
 * be either matched to an internal record or explicitly excluded — and the
 * statement's own arithmetic (opening plus every line's movement equals
 * closing) is checked at that point too. Approval is a second officer's
 * sign-off that the period is genuinely settled; there is no path back to
 * InProgress once approved, the same reasoning a journal transaction is
 * corrected by a new entry rather than edited.
 */
enum BankReconciliationStatus: string
{
    case InProgress = 'in_progress';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
        };
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::InProgress => [self::PendingApproval],
            self::PendingApproval => [self::Approved],
            self::Approved => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
