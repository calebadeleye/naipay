<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Services;

use App\Domains\Approvals\Services\MakerCheckerGuard;
use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\Staff;
use App\Domains\LoanApplications\Approvals\LoanApplicationApproval;
use App\Domains\LoanApplications\Enums\LoanApplicationStatus;
use App\Domains\LoanApplications\Models\Guarantor;
use App\Domains\LoanApplications\Models\LoanApplication;
use App\Domains\LoanApplications\Notifications\LoanApplicationApprovedNotification;
use App\Domains\LoanApplications\Notifications\LoanApplicationRejectedNotification;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\Loans\Services\LoanCreationService;
use App\Support\Exceptions\DomainException;
use App\Support\Money\Money;
use App\Support\Sequences\ReferenceGenerator;
use Illuminate\Support\Facades\DB;

/**
 * The loan application workflow.
 *
 * Owns the path from Draft through assessment and recommendation to a
 * maker-checked approval, mirroring the shape of
 * `MerchantOnboardingService`: every state change is validated against the
 * status enum's transition graph, wrapped in a transaction, and paired with
 * an audit entry.
 */
final class LoanApplicationService
{
    private const MODULE = 'loan_applications';

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLogger $audit,
        private readonly MakerCheckerGuard $makerChecker,
        private readonly LoanCreationService $loans,
    ) {}

    /**
     * Creates an application in Draft.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, Staff $actor): LoanApplication
    {
        /** @var LoanProduct $product */
        $product = LoanProduct::query()->findOrFail($attributes['loan_product_id']);

        $amount = Money::fromDecimal((string) $attributes['requested_amount']);
        $tenor = (int) $attributes['requested_tenor'];

        $this->assertProductAccepts($product, $amount, $tenor);

        return DB::transaction(function () use ($attributes, $amount, $tenor, $actor): LoanApplication {
            $application = new LoanApplication($attributes);

            $application->requested_amount = $amount;
            $application->requested_tenor = $tenor;
            $application->application_number = $this->references->next('loan_application');
            $application->status = LoanApplicationStatus::Draft;
            $application->created_by = $actor->getKey();

            // Defaults to the merchant's branch, so a branch-scoped officer
            // never creates a record they cannot then see.
            if (empty($application->branch_id)) {
                $application->branch_id = $application->merchant?->branch_id;
            }

            $application->save();

            $this->audit->recordCreation('loan_application.created', self::MODULE, $application, $actor);

            return $application->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(LoanApplication $application, array $attributes, Staff $actor): LoanApplication
    {
        $this->assertEditable($application);

        $product = LoanProduct::query()->findOrFail($attributes['loan_product_id'] ?? $application->loan_product_id);

        $amount = array_key_exists('requested_amount', $attributes)
            ? Money::fromDecimal((string) $attributes['requested_amount'])
            : $application->requested_amount;

        $tenor = array_key_exists('requested_tenor', $attributes)
            ? (int) $attributes['requested_tenor']
            : $application->requested_tenor;

        $this->assertProductAccepts($product, $amount, $tenor);

        return DB::transaction(function () use ($application, $attributes, $amount, $tenor, $actor): LoanApplication {
            $before = $application->getAttributes();

            $application->fill($attributes);
            $application->requested_amount = $amount;
            $application->requested_tenor = $tenor;
            $application->save();

            $this->audit->recordChange('loan_application.updated', self::MODULE, $application, $before, actor: $actor);

            return $application->fresh();
        });
    }

    /**
     * Submits a draft for assessment.
     *
     * A merchant who cannot yet borrow, or a business not yet verified, is
     * refused here rather than at approval: catching it at submission saves
     * credit staff reviewing a file that was never going anywhere.
     */
    public function submit(LoanApplication $application, Staff $actor): LoanApplication
    {
        $this->assertTransition($application, LoanApplicationStatus::Submitted);
        $this->assertMerchantAndBusinessAreEligible($application);
        $this->assertHasSufficientGuarantors($application);

        $validityDays = (int) config('naipay.loans.application_validity_days', 60);

        return $this->transition(
            $application,
            LoanApplicationStatus::Submitted,
            'loan_application.submitted',
            $actor,
            extra: [
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
                'expires_at' => now()->addDays($validityDays),
            ],
        );
    }

    public function assess(LoanApplication $application, string $notes, Staff $actor): LoanApplication
    {
        $this->assertTransition($application, LoanApplicationStatus::UnderAssessment);

        return $this->transition(
            $application,
            LoanApplicationStatus::UnderAssessment,
            'loan_application.assessed',
            $actor,
            extra: [
                'assessment_notes' => $notes,
                'assessed_by' => $actor->getKey(),
                'assessed_at' => now(),
            ],
        );
    }

    public function recommend(LoanApplication $application, string $notes, Staff $actor): LoanApplication
    {
        $this->assertTransition($application, LoanApplicationStatus::Recommended);

        return $this->transition(
            $application,
            LoanApplicationStatus::Recommended,
            'loan_application.recommended',
            $actor,
            extra: [
                'recommendation_notes' => $notes,
                'recommended_by' => $actor->getKey(),
                'recommended_at' => now(),
            ],
        );
    }

    /**
     * Final approval. Subject to maker-checker: the officer who created the
     * application must not be the one who approves it.
     *
     * The credit manager may approve a lower amount or a shorter tenor than
     * requested; both default to what was asked for.
     */
    public function approve(
        LoanApplication $application,
        Staff $actor,
        ?Money $approvedAmount = null,
        ?int $approvedTenor = null,
    ): LoanApplication {
        $this->assertTransition($application, LoanApplicationStatus::Approved);

        $this->makerChecker->assertCanApprove($actor, new LoanApplicationApproval($application));

        $this->assertMerchantAndBusinessAreEligible($application);
        $this->assertHasSufficientGuarantors($application);

        /** @var LoanProduct $product */
        $product = $application->loanProduct()->firstOrFail();

        $amount = $approvedAmount ?? $application->requested_amount;
        $tenor = $approvedTenor ?? $application->requested_tenor;

        $this->assertProductAccepts($product, $amount, $tenor);

        return DB::transaction(function () use ($application, $amount, $tenor, $product, $actor): LoanApplication {
            $approved = $this->transition(
                $application,
                LoanApplicationStatus::Approved,
                'loan_application.approved',
                $actor,
                extra: [
                    'approved_amount' => $amount,
                    'approved_tenor' => $tenor,
                    'approved_interest_rate' => $product->interest_rate,
                    'approved_by' => $actor->getKey(),
                    'approved_at' => now(),
                    'decision_reason' => null,
                ],
            );

            // An approved application without a loan behind it is a state
            // that must never exist, so the loan is created here, inside the
            // same transaction, rather than as a separate step a caller could
            // forget.
            $this->loans->createFromApplication($approved, $actor);

            $approved->loadMissing('merchant');
            $approved->merchant?->notify(new LoanApplicationApprovedNotification($approved));

            return $approved;
        });
    }

    public function reject(LoanApplication $application, string $reason, Staff $actor): LoanApplication
    {
        $this->assertTransition($application, LoanApplicationStatus::Rejected);

        $rejected = $this->transition(
            $application,
            LoanApplicationStatus::Rejected,
            'loan_application.rejected',
            $actor,
            reason: $reason,
            extra: [
                'decision_reason' => $reason,
                'rejected_by' => $actor->getKey(),
                'rejected_at' => now(),
            ],
        );

        $rejected->loadMissing('merchant');
        $rejected->merchant?->notify(new LoanApplicationRejectedNotification($rejected, $reason));

        return $rejected;
    }

    /**
     * Returns a rejected application to Draft for rework, so the file is
     * corrected rather than re-keyed from scratch.
     */
    public function returnToDraft(LoanApplication $application, string $reason, Staff $actor): LoanApplication
    {
        $this->assertTransition($application, LoanApplicationStatus::Draft);

        return $this->transition(
            $application,
            LoanApplicationStatus::Draft,
            'loan_application.returned_to_draft',
            $actor,
            reason: $reason,
        );
    }

    public function withdraw(LoanApplication $application, string $reason, Staff $actor): LoanApplication
    {
        if (! $application->status->isEditable() && ! $application->status->isPendingDecision()) {
            throw new DomainException(
                "An application that is {$application->status->label()} cannot be withdrawn.",
            );
        }

        $this->assertTransition($application, LoanApplicationStatus::Withdrawn);

        return $this->transition(
            $application,
            LoanApplicationStatus::Withdrawn,
            'loan_application.withdrawn',
            $actor,
            reason: $reason,
            extra: [
                'withdrawal_reason' => $reason,
                'withdrawn_by' => $actor->getKey(),
                'withdrawn_at' => now(),
            ],
        );
    }

    /**
     * Adds a guarantor to an application still open to change.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addGuarantor(LoanApplication $application, array $attributes, Staff $actor): Guarantor
    {
        $this->assertMutableForGuarantors($application);

        return DB::transaction(function () use ($application, $attributes, $actor): Guarantor {
            $guarantor = new Guarantor($attributes);
            $guarantor->loan_application_id = $application->getKey();
            $guarantor->created_by = $actor->getKey();
            $guarantor->save();

            $this->audit->recordCreation('loan_application.guarantor_added', self::MODULE, $guarantor, $actor);

            return $guarantor->fresh();
        });
    }

    public function removeGuarantor(LoanApplication $application, Guarantor $guarantor, Staff $actor): void
    {
        $this->assertMutableForGuarantors($application);

        DB::transaction(function () use ($guarantor, $actor): void {
            $guarantor->delete();

            $this->audit->record(
                action: 'loan_application.guarantor_removed',
                module: self::MODULE,
                subject: $guarantor,
                oldValues: $guarantor->getAttributes(),
                eventType: 'delete',
                actor: $actor,
            );
        });
    }

    /**
     * Sweeps every application awaiting a decision whose validity window has
     * lapsed to Expired. A system action: there is no actor to blame for the
     * calendar.
     */
    public function expireLapsed(): int
    {
        $lapsed = LoanApplication::query()
            ->whereIn('status', array_map(
                static fn (LoanApplicationStatus $status): string => $status->value,
                [LoanApplicationStatus::Submitted, LoanApplicationStatus::UnderAssessment, LoanApplicationStatus::Recommended],
            ))
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($lapsed as $application) {
            $this->transition(
                $application,
                LoanApplicationStatus::Expired,
                'loan_application.expired',
                actor: null,
            );
        }

        return $lapsed->count();
    }

    // --- Internals -----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(
        LoanApplication $application,
        LoanApplicationStatus $status,
        string $action,
        ?Staff $actor,
        ?string $reason = null,
        array $extra = [],
    ): LoanApplication {
        return DB::transaction(function () use ($application, $status, $action, $actor, $reason, $extra): LoanApplication {
            $before = $application->getAttributes();

            $application->forceFill(array_merge(['status' => $status], $extra))->save();

            $this->audit->recordChange($action, self::MODULE, $application, $before, reason: $reason, actor: $actor);

            return $application->fresh();
        });
    }

    private function assertTransition(LoanApplication $application, LoanApplicationStatus $target): void
    {
        $current = $application->status;

        if (! $current->canTransitionTo($target)) {
            throw new DomainException(
                "An application that is {$current->label()} cannot move to {$target->label()}.",
            );
        }
    }

    private function assertEditable(LoanApplication $application): void
    {
        if (! $application->status->isEditable()) {
            throw new DomainException(
                "This application is {$application->status->label()} and can no longer be edited. "
                .'Return it to draft first.',
            );
        }
    }

    private function assertProductAccepts(LoanProduct $product, Money $amount, int $tenor): void
    {
        if (! $product->isActive()) {
            throw new DomainException("{$product->name} is no longer available.");
        }

        if (! $product->acceptsAmount($amount)) {
            throw new DomainException(
                "{$product->name} lends between {$product->minimum_amount->format()} and {$product->maximum_amount->format()}.",
                ['requested_amount' => ['The amount is outside this product’s limits.']],
            );
        }

        if (! $product->acceptsTenor($tenor)) {
            $unit = mb_strtolower($product->tenor_unit->label());

            throw new DomainException(
                "{$product->name} runs for {$product->minimum_tenor} to {$product->maximum_tenor} {$unit}.",
                ['requested_tenor' => ['The tenor is outside this product’s limits.']],
            );
        }
    }

    /**
     * Every application must be against a merchant who can borrow and a
     * verified business before it may proceed past Draft.
     */
    private function assertMerchantAndBusinessAreEligible(LoanApplication $application): void
    {
        $merchant = $application->merchant()->firstOrFail();
        $business = $application->business()->firstOrFail();

        if (! $merchant->canBorrow()) {
            throw new DomainException(
                'This merchant is not yet eligible to borrow. Complete onboarding and KYC first.',
            );
        }

        if (! $business->isVerified()) {
            throw new DomainException(
                'This business has not yet been verified.',
            );
        }
    }

    /**
     * A product that requires guarantors must have at least its configured
     * minimum before the application can move past Draft.
     */
    private function assertHasSufficientGuarantors(LoanApplication $application): void
    {
        if ($application->hasSufficientGuarantors()) {
            return;
        }

        $product = $application->loanProduct;

        throw new DomainException(
            "{$product->name} requires at least {$product->minimum_guarantors} guarantor(s) before this application can proceed.",
        );
    }

    /**
     * Guarantors may be added or removed at any point up to a final decision:
     * an assessor may ask for one, and a rejected application reworked back
     * to Draft may need its list corrected.
     */
    private function assertMutableForGuarantors(LoanApplication $application): void
    {
        $locked = [LoanApplicationStatus::Approved, LoanApplicationStatus::Withdrawn, LoanApplicationStatus::Expired];

        if (in_array($application->status, $locked, true)) {
            throw new DomainException(
                "An application that is {$application->status->label()} can no longer have its guarantors changed.",
            );
        }
    }
}
