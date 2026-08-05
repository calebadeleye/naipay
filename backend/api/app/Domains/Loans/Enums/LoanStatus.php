<?php

declare(strict_types=1);

namespace App\Domains\Loans\Enums;

/**
 * A loan's lifecycle.
 *
 * Three approvals stand between an application and money actually moving,
 * each held by a different function: `LoanApplicationStatus::Approved` is the
 * credit decision, `PendingApproval` → `PendingDisbursement` is a second
 * confirmation that the loan contract as generated is correct, and only then
 * can Finance disburse. Separating credit approval from disbursement is the
 * entire point of the maker-checker chain here.
 *
 * PastDue, Delinquent and Closed are deliberately absent: classifying a loan
 * by days overdue and closing it on full repayment both depend on repayment
 * data this phase does not yet have, and are added once Phase 9 exists.
 */
enum LoanStatus: string
{
    case PendingApproval = 'pending_approval';
    case PendingDisbursement = 'pending_disbursement';
    case Disbursed = 'disbursed';
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'Pending approval',
            self::PendingDisbursement => 'Pending disbursement',
            self::Disbursed => 'Disbursed',
            self::WrittenOff => 'Written off',
        };
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingApproval => [self::PendingDisbursement],
            self::PendingDisbursement => [self::Disbursed],
            self::Disbursed => [self::WrittenOff],
            self::WrittenOff => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isOutstanding(): bool
    {
        return $this === self::Disbursed;
    }
}
