<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Enums;

/**
 * The merchant onboarding workflow.
 *
 * Transitions are explicit rather than free — a merchant must not jump from
 * Draft to Approved, and the states before Approved are exactly where
 * verification and maker-checker live.
 */
enum OnboardingStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case PendingVerification = 'pending_verification';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::PendingVerification => 'Pending verification',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    /**
     * States this one may move to.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Closed],
            self::Submitted => [self::PendingVerification, self::Draft, self::Rejected],
            self::PendingVerification => [self::PendingApproval, self::Draft, self::Rejected],
            self::PendingApproval => [self::Approved, self::Rejected, self::PendingVerification],
            // A rejected merchant can be reworked rather than re-keyed.
            self::Rejected => [self::Draft],
            self::Approved => [self::Suspended, self::Closed],
            self::Suspended => [self::Approved, self::Closed],
            // Terminal.
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Whether the record is still being edited by onboarding staff. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /** Whether the merchant may hold loans and transact. */
    public function isOperational(): bool
    {
        return $this === self::Approved;
    }
}
