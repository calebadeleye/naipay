<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Data;

use App\Support\Exceptions\FinancialIntegrityException;
use App\Support\Money\Money;

/**
 * A repayment amount, fully accounted for.
 *
 * Self-checking, the same discipline as LoanSchedule: every kobo of the
 * original amount lands somewhere — an instalment's principal, interest or
 * fee, the merchant's own account as excess, or suspense as unallocated —
 * and the constructor refuses a plan that does not reconcile exactly.
 *
 * @param  array<int, AllocationLine>  $entryAllocations
 */
final class AllocationPlan
{
    public function __construct(
        public readonly Money $amount,
        public readonly array $entryAllocations,
        public readonly Money $principalTotal,
        public readonly Money $interestTotal,
        public readonly Money $feeTotal,
        public readonly Money $excessTotal,
        public readonly Money $unallocatedTotal,
    ) {
        $accounted = $principalTotal->plus($interestTotal)->plus($feeTotal)->plus($excessTotal)->plus($unallocatedTotal);

        if (! $accounted->equals($amount)) {
            throw new FinancialIntegrityException(
                'A repayment allocation must account for the full amount, exactly.',
                ['amount' => $amount->toDecimalString(), 'accounted' => $accounted->toDecimalString()],
            );
        }
    }

    public function isFullyAllocatedToTheLoan(): bool
    {
        return $this->excessTotal->isZero() && $this->unallocatedTotal->isZero();
    }
}
