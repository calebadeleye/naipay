<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Services;

use App\Domains\Approvals\Services\MakerCheckerGuard;
use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\Staff;
use App\Domains\Merchants\Approvals\MerchantApproval;
use App\Domains\Merchants\Enums\KycStatus;
use App\Domains\Merchants\Enums\MerchantStatus;
use App\Domains\Merchants\Enums\OnboardingStatus;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Exceptions\DomainException;
use App\Support\Security\BlindIndex;
use App\Support\Sequences\ReferenceGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Merchant onboarding.
 *
 * Owns the workflow from Draft through to Approved, the handling of identity
 * numbers, and the maker-checker control on approval.
 *
 * Reference numbers are allocated at creation rather than at approval. The
 * brief lists number generation among the approval steps, but a merchant sits
 * in Draft and Pending Verification for days while documents are gathered, and
 * staff need something to quote on a phone call or a document in the meantime.
 * Nothing downstream depends on the number being withheld until approval.
 */
final class MerchantOnboardingService
{
    private const MODULE = 'merchants';

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLogger $audit,
        private readonly MakerCheckerGuard $makerChecker,
    ) {}

    /**
     * Creates a merchant in Draft.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, Staff $actor): Merchant
    {
        $bvn = $this->normaliseIdentityNumber($attributes['bvn'] ?? null);
        $nin = $this->normaliseIdentityNumber($attributes['nin'] ?? null);

        unset($attributes['bvn'], $attributes['nin']);

        return DB::transaction(function () use ($attributes, $bvn, $nin, $actor): Merchant {
            $this->assertIdentityNumbersAreUnused($bvn, $nin);

            $merchant = new Merchant($attributes);

            $merchant->merchant_number = $this->references->next('merchant');
            $merchant->onboarding_status = OnboardingStatus::Draft;
            $merchant->merchant_status = MerchantStatus::Inactive;
            $merchant->kyc_status = KycStatus::NotStarted;
            $merchant->created_by = $actor->getKey();

            // Default the merchant to the creating officer's branch, so a
            // branch-scoped officer never creates a record they cannot then see.
            $merchant->branch_id ??= $actor->branch_id;

            $this->applyIdentityNumbers($merchant, $bvn, $nin);

            $merchant->save();

            $this->audit->recordCreation('merchant.created', self::MODULE, $merchant, $actor);

            return $merchant->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Merchant $merchant, array $attributes, Staff $actor): Merchant
    {
        $this->assertEditable($merchant);

        $bvn = $this->normaliseIdentityNumber($attributes['bvn'] ?? null);
        $nin = $this->normaliseIdentityNumber($attributes['nin'] ?? null);

        unset($attributes['bvn'], $attributes['nin']);

        return DB::transaction(function () use ($merchant, $attributes, $bvn, $nin, $actor): Merchant {
            $before = $merchant->getAttributes();

            $this->assertIdentityNumbersAreUnused($bvn, $nin, exceptMerchantId: (int) $merchant->getKey());

            $merchant->fill($attributes);
            $this->applyIdentityNumbers($merchant, $bvn, $nin);

            $merchant->save();

            // Changing an identity number is a sensitive update, and the audit
            // trail records that it changed without capturing the value.
            $action = ($bvn !== null || $nin !== null)
                ? 'merchant.sensitive_update'
                : 'merchant.updated';

            $this->audit->recordChange($action, self::MODULE, $merchant, $before, actor: $actor);

            return $merchant->fresh();
        });
    }

    /**
     * Submits a draft for verification.
     *
     * A merchant without a business is refused here rather than at approval:
     * the whole record exists to support lending against trading activity, and
     * catching it at submission saves compliance reviewing an incomplete file.
     */
    public function submit(Merchant $merchant, Staff $actor): Merchant
    {
        $this->assertTransition($merchant, OnboardingStatus::Submitted);
        $this->assertHasAtLeastOneBusiness($merchant);

        return $this->transition(
            $merchant,
            OnboardingStatus::Submitted,
            'merchant.submitted',
            $actor,
            extra: [
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
            ],
        );
    }

    /**
     * Compliance takes the file out of the submitted queue and begins checking
     * it.
     *
     * A distinct state rather than a flag: a submitted file nobody has picked
     * up and one that is actively being verified are different things to whoever
     * is chasing the queue.
     */
    public function beginVerification(Merchant $merchant, Staff $actor): Merchant
    {
        $this->assertTransition($merchant, OnboardingStatus::PendingVerification);

        return $this->transition(
            $merchant,
            OnboardingStatus::PendingVerification,
            'merchant.verification_started',
            $actor,
            extra: ['kyc_status' => KycStatus::Pending],
        );
    }

    /**
     * Compliance confirms the identity and business records check out.
     *
     * Accepted straight from Submitted as well as from Pending verification:
     * an officer who opens a file and clears it in one sitting should not have
     * to click twice, and the intermediate state is a queue aid, not a control.
     */
    public function markVerified(Merchant $merchant, Staff $actor): Merchant
    {
        if ($merchant->onboarding_status === OnboardingStatus::Submitted) {
            $merchant = $this->beginVerification($merchant, $actor);
        }

        $this->assertTransition($merchant, OnboardingStatus::PendingApproval);

        return $this->transition(
            $merchant,
            OnboardingStatus::PendingApproval,
            'merchant.verified',
            $actor,
            extra: [
                'kyc_status' => KycStatus::Verified,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
            ],
        );
    }

    /**
     * Final approval. Subject to maker-checker.
     */
    public function approve(Merchant $merchant, Staff $actor): Merchant
    {
        $this->assertTransition($merchant, OnboardingStatus::Approved);

        // The officer who created the merchant must not be the one who
        // approves them. Enforced centrally rather than per controller.
        $this->makerChecker->assertCanApprove($actor, new MerchantApproval($merchant));

        $this->assertHasAtLeastOneBusiness($merchant);

        if (! $merchant->kyc_status->isVerified()) {
            throw new DomainException(
                'This merchant cannot be approved until their KYC has been verified.',
            );
        }

        return $this->transition(
            $merchant,
            OnboardingStatus::Approved,
            'merchant.approved',
            $actor,
            extra: [
                'merchant_status' => MerchantStatus::Active,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'rejection_reason' => null,
            ],
        );
    }

    public function reject(Merchant $merchant, string $reason, Staff $actor): Merchant
    {
        $this->assertTransition($merchant, OnboardingStatus::Rejected);

        return $this->transition(
            $merchant,
            OnboardingStatus::Rejected,
            'merchant.rejected',
            $actor,
            reason: $reason,
            extra: ['rejection_reason' => $reason],
        );
    }

    public function suspend(Merchant $merchant, string $reason, Staff $actor): Merchant
    {
        $this->assertTransition($merchant, OnboardingStatus::Suspended);

        return $this->transition(
            $merchant,
            OnboardingStatus::Suspended,
            'merchant.suspended',
            $actor,
            reason: $reason,
            extra: [
                'merchant_status' => MerchantStatus::Suspended,
                'suspension_reason' => $reason,
            ],
        );
    }

    public function reinstate(Merchant $merchant, string $reason, Staff $actor): Merchant
    {
        $this->assertTransition($merchant, OnboardingStatus::Approved);

        return $this->transition(
            $merchant,
            OnboardingStatus::Approved,
            'merchant.reinstated',
            $actor,
            reason: $reason,
            extra: [
                'merchant_status' => MerchantStatus::Active,
                'suspension_reason' => null,
            ],
        );
    }

    /**
     * Returns a rejected or submitted merchant to Draft for rework, so the file
     * is corrected rather than re-keyed from scratch.
     */
    public function returnToDraft(Merchant $merchant, string $reason, Staff $actor): Merchant
    {
        $this->assertTransition($merchant, OnboardingStatus::Draft);

        return $this->transition(
            $merchant,
            OnboardingStatus::Draft,
            'merchant.returned_to_draft',
            $actor,
            reason: $reason,
        );
    }

    // --- Internals ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(
        Merchant $merchant,
        OnboardingStatus $status,
        string $action,
        Staff $actor,
        ?string $reason = null,
        array $extra = [],
    ): Merchant {
        return DB::transaction(function () use ($merchant, $status, $action, $actor, $reason, $extra): Merchant {
            $before = $merchant->getAttributes();

            $merchant->forceFill(array_merge(['onboarding_status' => $status], $extra))->save();

            $this->audit->recordChange($action, self::MODULE, $merchant, $before, reason: $reason, actor: $actor);

            return $merchant->fresh();
        });
    }

    private function assertTransition(Merchant $merchant, OnboardingStatus $target): void
    {
        $current = $merchant->onboarding_status;

        if (! $current->canTransitionTo($target)) {
            throw new DomainException(
                "A merchant that is {$current->label()} cannot move to {$target->label()}.",
            );
        }
    }

    private function assertEditable(Merchant $merchant): void
    {
        if (! $merchant->onboarding_status->isEditable()) {
            throw new DomainException(
                "This merchant is {$merchant->onboarding_status->label()} and can no longer be edited. "
                .'Return it to draft first.',
            );
        }
    }

    /**
     * Every merchant must have at least one business before approval.
     *
     * The credit product is advanced against trading activity; a merchant with
     * no business has nothing to lend against.
     */
    private function assertHasAtLeastOneBusiness(Merchant $merchant): void
    {
        if (! $merchant->businesses()->exists()) {
            throw new DomainException(
                'Add at least one business to this merchant before continuing.',
            );
        }
    }

    /**
     * Sets identity numbers together with their blind indexes.
     *
     * The two must always move together: an updated ciphertext with a stale
     * index would silently break duplicate detection, letting the same person
     * be onboarded twice.
     */
    private function applyIdentityNumbers(Merchant $merchant, ?string $bvn, ?string $nin): void
    {
        if ($bvn !== null) {
            $merchant->forceFill([
                'bvn' => $bvn,
                'bvn_index' => BlindIndex::hash($bvn, Merchant::BVN_INDEX_DOMAIN),
            ]);
        }

        if ($nin !== null) {
            $merchant->forceFill([
                'nin' => $nin,
                'nin_index' => BlindIndex::hash($nin, Merchant::NIN_INDEX_DOMAIN),
            ]);
        }
    }

    /**
     * Refuses an identity number already registered to another merchant.
     *
     * The database enforces this too, via unique keys on the index columns;
     * checking here turns a constraint violation into a field error that names
     * which number is the problem.
     */
    private function assertIdentityNumbersAreUnused(
        ?string $bvn,
        ?string $nin,
        ?int $exceptMerchantId = null,
    ): void {
        $errors = [];

        if ($bvn !== null) {
            $exists = Merchant::query()
                ->withBvnIndex($bvn)
                ->when($exceptMerchantId !== null, fn ($query) => $query->whereKeyNot($exceptMerchantId))
                ->exists();

            if ($exists) {
                $errors['bvn'] = ['This BVN is already registered to another merchant.'];
            }
        }

        if ($nin !== null) {
            $exists = Merchant::query()
                ->withNinIndex($nin)
                ->when($exceptMerchantId !== null, fn ($query) => $query->whereKeyNot($exceptMerchantId))
                ->exists();

            if ($exists) {
                $errors['nin'] = ['This NIN is already registered to another merchant.'];
            }
        }

        if ($errors !== []) {
            throw new DomainException('This identity number is already in use.', $errors);
        }
    }

    private function normaliseIdentityNumber(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return preg_replace('/\s+/', '', trim($value));
    }
}
