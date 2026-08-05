<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Enums;

/**
 * A journal transaction is posted once and stays posted. There is no draft
 * state — by the time `LedgerPostingService::post()` returns, the entries
 * already exist and already balance, because the alternative is a half-posted
 * transaction sitting in the database.
 *
 * The only thing that can happen to a posted transaction afterwards is a
 * reversal, which is itself a second, independent, balanced posting — never an
 * edit to this one.
 */
enum JournalTransactionStatus: string
{
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
        };
    }
}
