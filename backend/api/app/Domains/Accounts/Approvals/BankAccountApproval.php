<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Approvals;

use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Approvals\Contracts\RequiresMakerChecker;

/**
 * Adapts a bank account to the maker-checker contract.
 *
 * Adding — or materially changing — a place real money flows through is the
 * highest-risk action in this domain, so the officer who proposed it can
 * never be the one who approves it.
 */
final class BankAccountApproval implements RequiresMakerChecker
{
    public function __construct(
        private readonly BankAccount $account,
    ) {}

    public function makerId(): ?int
    {
        return $this->account->created_by;
    }

    public function makerCheckerOperation(): string
    {
        return 'bank_account.change';
    }
}
