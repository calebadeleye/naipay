<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Enums;

enum BusinessStatus: string
{
    case Inactive = 'inactive';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Inactive => 'Inactive',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}
