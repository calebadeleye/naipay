<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Enums;

/**
 * The loan application workflow.
 *
 * Transitions are explicit rather than free, the same reasoning as
 * `OnboardingStatus`: an application must not jump from Draft to Approved,
 * and the states in between are exactly where assessment, recommendation and
 * maker-checker live.
 */
enum LoanApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderAssessment = 'under_assessment';
    case Recommended = 'recommended';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderAssessment => 'Under assessment',
            self::Recommended => 'Recommended',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Withdrawn => 'Withdrawn',
            self::Expired => 'Expired',
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
            self::Draft => [self::Submitted, self::Withdrawn],
            self::Submitted => [self::UnderAssessment, self::Draft, self::Rejected, self::Withdrawn, self::Expired],
            self::UnderAssessment => [self::Recommended, self::Draft, self::Rejected, self::Withdrawn, self::Expired],
            self::Recommended => [self::Approved, self::Rejected, self::UnderAssessment, self::Withdrawn, self::Expired],
            // A rejected application can be reworked rather than re-keyed.
            self::Rejected => [self::Draft],
            // Terminal.
            self::Approved, self::Withdrawn, self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Whether the record is still being edited by originating staff. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /**
     * Awaiting a decision: eligible for withdrawal by the applicant's officer
     * and for the expiry sweep once past its validity window.
     */
    public function isPendingDecision(): bool
    {
        return in_array($this, [self::Submitted, self::UnderAssessment, self::Recommended], true);
    }
}
