<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * Lifecycle of a staff account.
 *
 * Only Active may authenticate. A staff record is never deleted — it stays
 * attributable for the loans it approved and the repayments it recorded — so
 * removing someone's access means moving them to Disabled.
 */
enum StaffStatus: string
{
    /** Created but the first sign-in has not happened yet. */
    case PendingActivation = 'pending_activation';

    case Active = 'active';

    /** Temporarily blocked, typically pending an investigation. */
    case Suspended = 'suspended';

    /** Permanently revoked, e.g. the person has left. */
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::PendingActivation => 'Pending activation',
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
     * Deliberately non-specific about whether the account exists — an attacker
     * probing identifiers should not learn which are real.
     */
    public function refusalReason(): ?string
    {
        return match ($this) {
            self::Active => null,
            self::PendingActivation => 'This account has not been activated. Contact your administrator.',
            self::Suspended => 'This account is suspended. Contact your administrator.',
            self::Disabled => 'This account is no longer active.',
        };
    }
}
