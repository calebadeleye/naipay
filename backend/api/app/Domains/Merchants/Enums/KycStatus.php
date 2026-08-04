<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Enums;

/**
 * Know-your-customer verification state.
 *
 * Tracked separately from onboarding status because they answer different
 * questions: onboarding is "where is this record in the process?", KYC is "has
 * this person's identity actually been verified?" A merchant can be approved
 * with KYC expiring in three months, and both facts need to be visible.
 */
enum KycStatus: string
{
    case NotStarted = 'not_started';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    /** Documents were verified but have since lapsed. */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::Pending => 'Pending',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }
}
