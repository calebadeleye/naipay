<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Enums;

enum BankAccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    public function canTransact(): bool
    {
        return $this === self::Active;
    }
}
