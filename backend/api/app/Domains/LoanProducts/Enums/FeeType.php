<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Enums;

/**
 * How a fee or penalty is expressed.
 */
enum FeeType: string
{
    case None = 'none';

    /** A fixed amount in Naira. */
    case Fixed = 'fixed';

    /** A percentage of the principal. */
    case Percentage = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Fixed => 'Fixed amount',
            self::Percentage => 'Percentage of principal',
        };
    }

    public function isCharged(): bool
    {
        return $this !== self::None;
    }
}
