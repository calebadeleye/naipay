<?php

declare(strict_types=1);

namespace App\Domains\Branches\Enums;

/**
 * Lifecycle of a branch.
 *
 * A branch is never deleted — it is referenced by every loan and repayment ever
 * booked against it — so withdrawing it from use means closing it.
 */
enum BranchStatus: string
{
    case Active = 'active';

    /** Temporarily not transacting; existing records stay readable. */
    case Suspended = 'suspended';

    /** Permanently withdrawn. Historical records remain intact. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    /**
     * Whether new merchants, loans and staff may be assigned here.
     */
    public function acceptsNewBusiness(): bool
    {
        return $this === self::Active;
    }
}
