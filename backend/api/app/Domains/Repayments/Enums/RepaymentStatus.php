<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Enums;

/**
 * A repayment's workflow.
 *
 * Three officers, three different functions, the same segregation the loan
 * side is built on: whoever records it is reporting what the bank shows;
 * whoever verifies it is confirming that against the bank evidence; whoever
 * approves it is the one whose action actually moves money — allocation and
 * the ledger posting both happen at that step, never before. A reversal
 * undoes an approval precisely; nothing before approval needs reversing,
 * since nothing before it touched a balance.
 */
enum RepaymentStatus: string
{
    case Recorded = 'recorded';
    case Verified = 'verified';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Recorded',
            self::Verified => 'Verified',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Reversed => 'Reversed',
        };
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Recorded => [self::Verified, self::Rejected],
            self::Verified => [self::Approved, self::Rejected],
            self::Approved => [self::Reversed],
            self::Rejected, self::Reversed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
