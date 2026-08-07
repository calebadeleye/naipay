<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Services;

use App\Domains\Accounts\Models\MerchantAccount;
use App\Domains\Approvals\Services\ApprovalNotifier;
use App\Domains\Approvals\Services\MakerCheckerGuard;
use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Data\JournalLine;
use App\Domains\Ledger\Services\LedgerPostingService;
use App\Domains\Ledger\Support\StandardAccounts;
use App\Domains\Loans\Enums\LoanScheduleEntryStatus;
use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Models\LoanScheduleEntry;
use App\Domains\Receipts\Services\ReceiptService;
use App\Domains\Repayments\Approvals\RepaymentApproval;
use App\Domains\Repayments\Approvals\RepaymentReversalApproval;
use App\Domains\Repayments\Data\AllocationLine;
use App\Domains\Repayments\Data\AllocationPlan;
use App\Domains\Repayments\Enums\RepaymentStatus;
use App\Domains\Repayments\Models\Repayment;
use App\Domains\Repayments\Models\RepaymentAllocation;
use App\Domains\Repayments\Notifications\RepaymentReceiptNotification;
use App\Support\Exceptions\DomainException;
use App\Support\Money\Money;
use App\Support\Sequences\ReferenceGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The repayment workflow: record, verify, approve, reject, reverse.
 *
 * Recording and verifying never move a kobo — an officer reporting what the
 * bank shows, and a second officer confirming it against the evidence.
 * Approval is the one step that allocates the amount against the loan's
 * schedule and posts the ledger entry, mirroring how LoanDisbursementService
 * is the one step in its own domain that moves money. A reversal undoes
 * exactly what approval did, using the same per-instalment breakdown it
 * wrote down at the time — never an approximation reconstructed later.
 */
final class RepaymentService
{
    private const MODULE = 'repayments';

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLogger $audit,
        private readonly MakerCheckerGuard $makerChecker,
        private readonly ApprovalNotifier $approvalNotifier,
        private readonly RepaymentAllocationService $allocator,
        private readonly LedgerPostingService $ledger,
        private readonly ReceiptService $receipts,
    ) {}

    /**
     * Records what the bank shows. Nothing here is final: a duplicate
     * warning can be overridden by a deliberate resubmission, and the record
     * itself can still be rejected before anyone approves it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes, Staff $actor, bool $confirmDuplicateWarning = false): Repayment
    {
        /** @var Loan $loan */
        $loan = Loan::query()->findOrFail($attributes['loan_id']);

        if (! $loan->isDisbursed()) {
            throw new DomainException(
                "{$loan->loan_reference} is {$loan->status->label()} and cannot receive a repayment.",
            );
        }

        $this->assertNotBlockedDuplicate($attributes);

        if (! $confirmDuplicateWarning) {
            $this->assertNoWarnedDuplicate($attributes);
        }

        return DB::transaction(function () use ($attributes, $loan, $actor): Repayment {
            $repayment = new Repayment($attributes);

            $repayment->repayment_reference = $this->references->next('repayment');
            $repayment->loan_id = $loan->getKey();
            $repayment->merchant_id = $loan->merchant_id;
            $repayment->business_id = $loan->business_id;
            $repayment->branch_id = $loan->branch_id;
            $repayment->status = RepaymentStatus::Recorded;
            $repayment->recorded_by = $actor->getKey();
            $repayment->save();

            $this->audit->recordCreation('repayment.recorded', self::MODULE, $repayment, $actor);

            return $repayment->fresh();
        });
    }

    public function verify(Repayment $repayment, ?string $notes, Staff $actor): Repayment
    {
        $this->assertTransition($repayment, RepaymentStatus::Verified);

        return DB::transaction(function () use ($repayment, $notes, $actor): Repayment {
            $before = $repayment->getAttributes();

            $repayment->forceFill([
                'status' => RepaymentStatus::Verified,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
                'verification_notes' => $notes,
            ])->save();

            $this->audit->recordChange('repayment.verified', self::MODULE, $repayment, $before, actor: $actor);

            return $repayment->fresh();
        });
    }

    public function reject(Repayment $repayment, string $reason, Staff $actor): Repayment
    {
        $this->assertTransition($repayment, RepaymentStatus::Rejected);

        return DB::transaction(function () use ($repayment, $reason, $actor): Repayment {
            $before = $repayment->getAttributes();

            $repayment->forceFill([
                'status' => RepaymentStatus::Rejected,
                'rejected_by' => $actor->getKey(),
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->audit->recordChange('repayment.rejected', self::MODULE, $repayment, $before, reason: $reason, actor: $actor);

            $this->approvalNotifier->notifyDecision(
                new RepaymentApproval($repayment),
                'rejected',
                $repayment->repayment_reference,
                "/repayments/{$repayment->id}",
                $actor,
                subject: $repayment,
                reason: $reason,
            );

            return $repayment->fresh();
        });
    }

    /**
     * The one step that moves money: allocates the amount across the loan's
     * schedule, posts the ledger entry, and updates every balance it
     * touches, all in one transaction.
     */
    public function approve(Repayment $repayment, Staff $actor): Repayment
    {
        $this->assertTransition($repayment, RepaymentStatus::Approved);

        $this->makerChecker->assertCanApprove($actor, new RepaymentApproval($repayment));

        return DB::transaction(function () use ($repayment, $actor): Repayment {
            /** @var Loan $loan */
            $loan = Loan::query()->lockForUpdate()->with('merchant')->findOrFail($repayment->loan_id);

            $plan = $this->allocator->plan($loan, $repayment->amount, $repayment->payment_date);

            if (! $plan->isFullyAllocatedToTheLoan() && ! (bool) config('naipay.allocation.allow_overpayment', true)) {
                throw new DomainException(
                    "{$repayment->repayment_reference} exceeds {$loan->loan_reference}'s outstanding balance, "
                    .'and overpayment is not permitted.',
                );
            }

            $before = $repayment->getAttributes();

            $transaction = $this->ledger->post(
                transactionType: 'repayment',
                description: "Repayment {$repayment->repayment_reference} against {$loan->loan_reference}.",
                lines: $this->ledgerLinesFor($repayment, $loan, $plan),
                sourceType: Repayment::class,
                sourceId: $repayment->getKey(),
                idempotencyKey: "repayment-approval:{$repayment->getKey()}",
                actor: $actor,
            );

            $this->applyPlanToSchedule($repayment, $plan);

            $loan->forceFill([
                'outstanding_principal' => $loan->outstanding_principal->minus($plan->principalTotal),
                'outstanding_interest' => $loan->outstanding_interest->minus($plan->interestTotal),
                'outstanding_fees' => $loan->outstanding_fees->minus($plan->feeTotal),
            ])->save();

            if ($plan->excessTotal->isPositive()) {
                $this->creditMerchantAccount($loan, $plan->excessTotal);
            }

            $repayment->forceFill([
                'status' => RepaymentStatus::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'allocated_principal' => $plan->principalTotal,
                'allocated_interest' => $plan->interestTotal,
                'allocated_fee' => $plan->feeTotal,
                'allocated_excess' => $plan->excessTotal,
                'allocated_unallocated' => $plan->unallocatedTotal,
                'repayment_journal_transaction_id' => $transaction->getKey(),
            ])->save();

            $this->audit->recordChange('repayment.approved', self::MODULE, $repayment, $before, actor: $actor);

            $this->approvalNotifier->notifyDecision(
                new RepaymentApproval($repayment),
                'approved',
                $repayment->repayment_reference,
                "/repayments/{$repayment->id}",
                $actor,
                subject: $repayment,
            );

            $repayment = $repayment->fresh('allocations');

            // An approved repayment without a receipt is a state that must
            // never exist, the same reasoning LoanCreationService is called
            // from inside LoanApplicationService::approve() rather than as a
            // separate step a caller could forget.
            $receipt = $this->receipts->generateFor($repayment);
            $loan->merchant?->notify(new RepaymentReceiptNotification($repayment, $receipt));

            return $repayment;
        });
    }

    /**
     * Undoes exactly what approve() did: the ledger posting, every
     * instalment's paid amounts, the loan's outstanding balances, and any
     * merchant-account credit — using the per-instalment breakdown approval
     * wrote down, not a reconstruction from totals.
     */
    public function reverse(Repayment $repayment, string $reason, Staff $actor): Repayment
    {
        if ($repayment->status !== RepaymentStatus::Approved) {
            throw new DomainException(
                "A repayment that is {$repayment->status->label()} cannot be reversed.",
            );
        }

        $this->makerChecker->assertCanApprove($actor, new RepaymentReversalApproval($repayment));
        $this->makerChecker->assertRecentlyReauthenticated($actor, 'repayment.reverse');

        return DB::transaction(function () use ($repayment, $reason, $actor): Repayment {
            /** @var Loan $loan */
            $loan = Loan::query()->lockForUpdate()->findOrFail($repayment->loan_id);

            $originalTransaction = $repayment->repaymentJournalTransaction()->firstOrFail();
            $reversal = $this->ledger->reverse($originalTransaction, $reason, $actor);

            foreach ($repayment->allocations()->with('scheduleEntry')->get() as $allocation) {
                /** @var RepaymentAllocation $allocation */
                $entry = $allocation->scheduleEntry;

                $entry->forceFill([
                    'principal_paid' => $entry->principal_paid->minus($allocation->principal_amount),
                    'interest_paid' => $entry->interest_paid->minus($allocation->interest_amount),
                    'fee_paid' => $entry->fee_paid->minus($allocation->fee_amount),
                ]);
                $entry->status = $this->scheduleEntryStatus($entry);
                $entry->save();
            }

            $loan->forceFill([
                'outstanding_principal' => $loan->outstanding_principal->plus($repayment->allocated_principal ?? Money::zero()),
                'outstanding_interest' => $loan->outstanding_interest->plus($repayment->allocated_interest ?? Money::zero()),
                'outstanding_fees' => $loan->outstanding_fees->plus($repayment->allocated_fee ?? Money::zero()),
            ])->save();

            if (($repayment->allocated_excess ?? Money::zero())->isPositive()) {
                $this->creditMerchantAccount($loan, $repayment->allocated_excess->negated());
            }

            $before = $repayment->getAttributes();

            $repayment->forceFill([
                'status' => RepaymentStatus::Reversed,
                'reversed_by' => $actor->getKey(),
                'reversed_at' => now(),
                'reversal_reason' => $reason,
                'reversal_journal_transaction_id' => $reversal->getKey(),
            ])->save();

            $this->audit->recordChange('repayment.reversed', self::MODULE, $repayment, $before, reason: $reason, actor: $actor);

            $this->approvalNotifier->notifyDecision(
                new RepaymentReversalApproval($repayment),
                'reversed',
                $repayment->repayment_reference,
                "/repayments/{$repayment->id}",
                $actor,
                subject: $repayment,
                reason: $reason,
            );

            return $repayment->fresh();
        });
    }

    /**
     * @return array<int, JournalLine>
     */
    private function ledgerLinesFor(Repayment $repayment, Loan $loan, AllocationPlan $plan): array
    {
        $lines = [
            JournalLine::debit(
                StandardAccounts::CASH_AT_BANK,
                $repayment->amount,
                merchantId: $loan->merchant_id,
                businessId: $loan->business_id,
                loanId: $loan->getKey(),
            ),
        ];

        if ($plan->principalTotal->isPositive()) {
            $lines[] = JournalLine::credit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, $plan->principalTotal, loanId: $loan->getKey(), merchantId: $loan->merchant_id, businessId: $loan->business_id);
        }

        if ($plan->interestTotal->isPositive()) {
            $lines[] = JournalLine::credit(StandardAccounts::INTEREST_INCOME, $plan->interestTotal, loanId: $loan->getKey(), merchantId: $loan->merchant_id, businessId: $loan->business_id);
        }

        if ($plan->feeTotal->isPositive()) {
            $lines[] = JournalLine::credit(StandardAccounts::PROCESSING_FEE_INCOME, $plan->feeTotal, loanId: $loan->getKey(), merchantId: $loan->merchant_id, businessId: $loan->business_id);
        }

        if ($plan->excessTotal->isPositive()) {
            $lines[] = JournalLine::credit(StandardAccounts::MERCHANT_SAVINGS_LIABILITY, $plan->excessTotal, loanId: $loan->getKey(), merchantId: $loan->merchant_id, businessId: $loan->business_id);
        }

        if ($plan->unallocatedTotal->isPositive()) {
            $lines[] = JournalLine::credit(StandardAccounts::SUSPENSE_ACCOUNT, $plan->unallocatedTotal, loanId: $loan->getKey(), merchantId: $loan->merchant_id, businessId: $loan->business_id);
        }

        return $lines;
    }

    private function applyPlanToSchedule(Repayment $repayment, AllocationPlan $plan): void
    {
        foreach ($plan->entryAllocations as $line) {
            /** @var AllocationLine $line */
            RepaymentAllocation::create([
                'repayment_id' => $repayment->getKey(),
                'loan_schedule_entry_id' => $line->loanScheduleEntryId,
                'principal_amount' => $line->principal,
                'interest_amount' => $line->interest,
                'fee_amount' => $line->fee,
            ]);

            /** @var LoanScheduleEntry $entry */
            $entry = LoanScheduleEntry::query()->lockForUpdate()->findOrFail($line->loanScheduleEntryId);

            $entry->forceFill([
                'principal_paid' => $entry->principal_paid->plus($line->principal),
                'interest_paid' => $entry->interest_paid->plus($line->interest),
                'fee_paid' => $entry->fee_paid->plus($line->fee),
            ]);
            $entry->status = $this->scheduleEntryStatus($entry);
            $entry->save();
        }
    }

    private function scheduleEntryStatus(LoanScheduleEntry $entry): LoanScheduleEntryStatus
    {
        $paid = $entry->principal_paid->plus($entry->interest_paid)->plus($entry->fee_paid);

        if ($paid->isZero()) {
            return LoanScheduleEntryStatus::Pending;
        }

        return $paid->greaterThanOrEqualTo($entry->totalDue()) ? LoanScheduleEntryStatus::Paid : LoanScheduleEntryStatus::PartiallyPaid;
    }

    private function creditMerchantAccount(Loan $loan, Money $amount): void
    {
        /** @var MerchantAccount $account */
        $account = MerchantAccount::query()
            ->where('merchant_id', $loan->merchant_id)
            ->lockForUpdate()
            ->firstOrFail();

        $account->forceFill([
            'available_balance' => $account->available_balance->plus($amount),
            'ledger_balance' => $account->ledger_balance->plus($amount),
        ])->save();
    }

    private function assertTransition(Repayment $repayment, RepaymentStatus $target): void
    {
        if (! $repayment->status->canTransitionTo($target)) {
            throw new DomainException(
                "A repayment that is {$repayment->status->label()} cannot move to {$target->label()}.",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertNotBlockedDuplicate(array $attributes): void
    {
        foreach (config('naipay.duplicate_detection.block', []) as $fields) {
            $match = $this->matching($fields, $attributes);

            if ($match !== null) {
                throw new DomainException(
                    "This matches {$match->repayment_reference}, recorded against the same bank reference. "
                    .'A duplicate repayment cannot be recorded.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertNoWarnedDuplicate(array $attributes): void
    {
        foreach (config('naipay.duplicate_detection.warn', []) as $fields) {
            $match = $this->matching($fields, $attributes);

            if ($match !== null) {
                throw new DomainException(
                    "This looks like a possible duplicate of {$match->repayment_reference}. "
                    .'Resubmit with confirm_duplicate=true if this is genuinely a separate payment.',
                );
            }
        }
    }

    /**
     * @param  array<int, string>  $fields
     * @param  array<string, mixed>  $attributes
     */
    private function matching(array $fields, array $attributes): ?Repayment
    {
        foreach ($fields as $field) {
            if (($attributes[$field] ?? null) === null) {
                return null;
            }
        }

        $lookbackDays = (int) config('naipay.duplicate_detection.lookback_days', 30);
        $paymentDate = Carbon::parse($attributes['payment_date']);

        $query = Repayment::query()
            ->whereNotIn('status', [RepaymentStatus::Rejected->value, RepaymentStatus::Reversed->value])
            ->whereBetween('payment_date', [
                $paymentDate->copy()->subDays($lookbackDays)->toDateString(),
                $paymentDate->copy()->addDays($lookbackDays)->toDateString(),
            ]);

        foreach ($fields as $field) {
            $query->where($field, $attributes[$field]);
        }

        return $query->first();
    }
}
