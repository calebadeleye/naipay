<?php

declare(strict_types=1);

namespace App\Domains\Documents\Enums;

enum DocumentVerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    /** Verified once, but the document has since lapsed. */
    case Expired = 'expired';

    /** Replaced by a newer version. */
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Superseded => 'Superseded',
        };
    }

    /**
     * Whether this document currently satisfies its requirement.
     *
     * Expired is deliberately not acceptable: a lapsed identity document is
     * evidence of who someone was, not who they are.
     */
    public function satisfiesRequirement(): bool
    {
        return $this === self::Verified;
    }
}
