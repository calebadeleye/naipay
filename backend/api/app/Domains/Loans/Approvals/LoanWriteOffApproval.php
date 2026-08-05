<?php

declare(strict_types=1);

namespace App\Domains\Loans\Approvals;

use App\Domains\Approvals\Contracts\RequiresMakerChecker;
use App\Domains\Loans\Models\Loan;

/**
 * Adapts a loan to the maker-checker control on `loan.write_off`: the officer
 * who confirmed the loan contract must not be the one who writes it off.
 */
final class LoanWriteOffApproval implements RequiresMakerChecker
{
    public function __construct(
        private readonly Loan $loan,
    ) {}

    public function makerId(): ?int
    {
        return $this->loan->approved_by;
    }

    public function makerCheckerOperation(): string
    {
        return 'loan.write_off';
    }
}
