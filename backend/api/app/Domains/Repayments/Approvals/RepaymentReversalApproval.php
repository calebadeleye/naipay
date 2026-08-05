<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Approvals;

use App\Domains\Approvals\Contracts\RequiresMakerChecker;
use App\Domains\Repayments\Models\Repayment;

/**
 * Adapts a repayment to the maker-checker control on `repayment.reverse`:
 * the officer whose approval moved the money must not be the one who
 * reverses it.
 */
final class RepaymentReversalApproval implements RequiresMakerChecker
{
    public function __construct(
        private readonly Repayment $repayment,
    ) {}

    public function makerId(): ?int
    {
        return $this->repayment->approved_by;
    }

    public function makerCheckerOperation(): string
    {
        return 'repayment.reverse';
    }
}
