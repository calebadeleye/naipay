<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Data;

use App\Support\Money\Money;

/**
 * One instalment's share of a repayment being allocated.
 */
final class AllocationLine
{
    public function __construct(
        public readonly int $loanScheduleEntryId,
        public readonly Money $principal,
        public readonly Money $interest,
        public readonly Money $fee,
    ) {}

    public function total(): Money
    {
        return $this->principal->plus($this->interest)->plus($this->fee);
    }
}
