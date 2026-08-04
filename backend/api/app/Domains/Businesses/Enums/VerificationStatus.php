<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Enums;

/**
 * Whether a business has been verified — typically by a field visit that
 * confirms the premises, stock and trading activity actually exist.
 */
enum VerificationStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Unverified',
            self::Pending => 'Pending verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }
}
