<?php

declare(strict_types=1);

namespace App\Domains\Loans\Enums;

/**
 * One instalment's settlement state.
 *
 * Every entry is created Pending at disbursement. The other cases exist for
 * the schema Phase 9's repayment allocation will write into; nothing in this
 * phase produces them yet.
 */
enum LoanScheduleEntryStatus: string
{
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
        };
    }
}
