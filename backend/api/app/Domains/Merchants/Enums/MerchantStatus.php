<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Enums;

/**
 * Whether an approved merchant is currently transacting.
 *
 * Distinct from onboarding status: onboarding describes how the record got
 * here, this describes what it may do now.
 */
enum MerchantStatus: string
{
    case Inactive = 'inactive';
    case Active = 'active';
    case Dormant = 'dormant';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Inactive => 'Inactive',
            self::Active => 'Active',
            self::Dormant => 'Dormant',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    /** Whether a new loan may be originated for this merchant. */
    public function canBorrow(): bool
    {
        return in_array($this, [self::Active, self::Dormant], true);
    }
}
