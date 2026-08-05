<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Data;

use App\Domains\Ledger\Enums\DebitCredit;
use App\Support\Money\Money;
use InvalidArgumentException;

/**
 * One line of a proposed posting: a debit or a credit against one account, by
 * its code.
 *
 * A plain value object rather than an Eloquent model — a line does not exist
 * independently of the transaction LedgerPostingService is about to build, and
 * giving it its own persistence would invite writing one outside a balanced
 * set.
 */
final class JournalLine
{
    private function __construct(
        public readonly string $accountCode,
        public readonly Money $amount,
        public readonly DebitCredit $side,
        public readonly ?string $description = null,
        public readonly ?int $merchantId = null,
        public readonly ?int $businessId = null,
        public readonly ?int $loanId = null,
        public readonly ?int $branchId = null,
    ) {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException(
                "A journal line for [{$accountCode}] must carry a positive amount.",
            );
        }
    }

    public static function debit(
        string $accountCode,
        Money $amount,
        ?string $description = null,
        ?int $merchantId = null,
        ?int $businessId = null,
        ?int $loanId = null,
        ?int $branchId = null,
    ): self {
        return new self($accountCode, $amount, DebitCredit::Debit, $description, $merchantId, $businessId, $loanId, $branchId);
    }

    public static function credit(
        string $accountCode,
        Money $amount,
        ?string $description = null,
        ?int $merchantId = null,
        ?int $businessId = null,
        ?int $loanId = null,
        ?int $branchId = null,
    ): self {
        return new self($accountCode, $amount, DebitCredit::Credit, $description, $merchantId, $businessId, $loanId, $branchId);
    }

    /**
     * The same line with its side and account swapped — the building block of
     * a reversal.
     */
    public function mirrored(string $toAccountCode): self
    {
        return new self(
            $toAccountCode,
            $this->amount,
            $this->side->opposite(),
            $this->description,
            $this->merchantId,
            $this->businessId,
            $this->loanId,
            $this->branchId,
        );
    }
}
