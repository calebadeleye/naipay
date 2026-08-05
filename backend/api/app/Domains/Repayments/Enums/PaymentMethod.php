<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Enums;

enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Pos = 'pos';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::Cash => 'Cash',
            self::Pos => 'POS',
            self::Other => 'Other',
        };
    }
}
