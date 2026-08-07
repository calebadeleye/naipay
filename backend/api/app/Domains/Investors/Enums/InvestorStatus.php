<?php

declare(strict_types=1);

namespace App\Domains\Investors\Enums;

/**
 * Lifecycle of an investor account.
 *
 * Simpler than StaffStatus: an investor account is provisioned directly by an
 * administrator with a working password, so there is no PendingActivation
 * state to model — only whether access is currently allowed.
 */
enum InvestorStatus: string
{
    case Active = 'active';

    /** Temporarily blocked, e.g. while a request for updated documentation is outstanding. */
    case Suspended = 'suspended';

    /** Permanently revoked, e.g. the investment has been exited. */
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Disabled => 'Disabled',
        };
    }

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    /**
     * Message shown when a sign-in is refused because of this status.
     *
     * Deliberately non-specific about whether the account exists — see
     * StaffStatus::refusalReason() for the same reasoning.
     */
    public function refusalReason(): ?string
    {
        return match ($this) {
            self::Active => null,
            self::Suspended => 'This account is suspended. Contact Every Merchant to regain access.',
            self::Disabled => 'This account is no longer active.',
        };
    }
}
