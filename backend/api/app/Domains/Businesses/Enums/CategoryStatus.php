<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Enums;

/**
 * Whether a business category may be chosen during onboarding.
 *
 * There is no deleted state. A category is deactivated, never removed:
 * businesses already filed under it keep their classification, and historical
 * category reports stay comparable year on year.
 */
enum CategoryStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }

    public function isSelectable(): bool
    {
        return $this === self::Active;
    }
}
