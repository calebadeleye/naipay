<?php

declare(strict_types=1);

namespace App\Domains\Loans\Approvals;

use App\Domains\Approvals\Contracts\RequiresMakerChecker;
use App\Domains\Loans\Models\Loan;

/**
 * Adapts a loan to the maker-checker control on `loan.approve`: the officer
 * whose application approval created it must not be the one who confirms the
 * loan contract itself.
 */
final class LoanApproval implements RequiresMakerChecker
{
    public function __construct(
        private readonly Loan $loan,
    ) {}

    public function makerId(): ?int
    {
        return $this->loan->created_by;
    }

    public function makerCheckerOperation(): string
    {
        return 'loan.approve';
    }
}
