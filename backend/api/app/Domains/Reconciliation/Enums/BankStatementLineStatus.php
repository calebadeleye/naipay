<?php

declare(strict_types=1);

namespace App\Domains\Reconciliation\Enums;

enum BankStatementLineStatus: string
{
    case Unmatched = 'unmatched';
    case Matched = 'matched';
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::Unmatched => 'Unmatched',
            self::Matched => 'Matched',
            self::Excluded => 'Excluded',
        };
    }
}
