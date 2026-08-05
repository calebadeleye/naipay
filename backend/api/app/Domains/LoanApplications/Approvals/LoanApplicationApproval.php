<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Approvals;

use App\Domains\Approvals\Contracts\RequiresMakerChecker;
use App\Domains\LoanApplications\Models\LoanApplication;

/**
 * Adapts a loan application to the maker-checker control: the officer who
 * created it must not be the one who approves it.
 */
final class LoanApplicationApproval implements RequiresMakerChecker
{
    public function __construct(
        private readonly LoanApplication $application,
    ) {}

    public function makerId(): ?int
    {
        return $this->application->created_by;
    }

    public function makerCheckerOperation(): string
    {
        return 'loan_application.approve';
    }
}
