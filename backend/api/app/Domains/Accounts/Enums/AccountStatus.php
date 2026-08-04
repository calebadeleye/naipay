<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Dormant = 'dormant';
    case Frozen = 'frozen';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Dormant => 'Dormant',
            self::Frozen => 'Frozen',
            self::Closed => 'Closed',
        };
    }

    public function canTransact(): bool
    {
        return in_array($this, [self::Active, self::Dormant], true);
    }
}
