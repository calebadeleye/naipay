<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Approvals;

use App\Domains\Approvals\Contracts\RequiresMakerChecker;
use App\Domains\Repayments\Models\Repayment;

/**
 * Adapts a repayment to the maker-checker control on `repayment.approve`:
 * the officer who verified it against the bank evidence must not be the one
 * whose approval then moves the money.
 */
final class RepaymentApproval implements RequiresMakerChecker
{
    public function __construct(
        private readonly Repayment $repayment,
    ) {}

    public function makerId(): ?int
    {
        return $this->repayment->verified_by;
    }

    public function makerCheckerOperation(): string
    {
        return 'repayment.approve';
    }
}
