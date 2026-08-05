<?php

declare(strict_types=1);

namespace App\Domains\Reconciliation\Enums;

/**
 * Which way money moved, from the bank's own perspective — matching the
 * bank statement's own layout rather than the ledger's debit/credit
 * convention, since that is what an officer is transcribing from.
 */
enum BankStatementLineDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Credit (money in)',
            self::Debit => 'Debit (money out)',
        };
    }
}
