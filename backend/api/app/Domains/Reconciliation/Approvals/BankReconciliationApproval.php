<?php

declare(strict_types=1);

namespace App\Domains\Reconciliation\Approvals;

use App\Domains\Approvals\Contracts\RequiresMakerChecker;
use App\Domains\Reconciliation\Models\BankReconciliation;

/**
 * Adapts a reconciliation to the maker-checker control on
 * `reconciliation.approve`: the officer who matched the statement's lines
 * must not be the one who signs off that the period is settled.
 */
final class BankReconciliationApproval implements RequiresMakerChecker
{
    public function __construct(
        private readonly BankReconciliation $reconciliation,
    ) {}

    public function makerId(): ?int
    {
        return $this->reconciliation->prepared_by;
    }

    public function makerCheckerOperation(): string
    {
        return 'reconciliation.approve';
    }
}
