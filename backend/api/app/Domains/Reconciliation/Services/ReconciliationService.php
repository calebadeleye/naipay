<?php

declare(strict_types=1);

namespace App\Domains\Reconciliation\Services;

use App\Domains\Approvals\Services\ApprovalNotifier;
use App\Domains\Approvals\Services\MakerCheckerGuard;
use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\Staff;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use App\Domains\Reconciliation\Approvals\BankReconciliationApproval;
use App\Domains\Reconciliation\Enums\BankReconciliationStatus;
use App\Domains\Reconciliation\Enums\BankStatementLineDirection;
use App\Domains\Reconciliation\Enums\BankStatementLineStatus;
use App\Domains\Reconciliation\Models\BankReconciliation;
use App\Domains\Reconciliation\Models\BankStatementLine;
use App\Domains\Repayments\Enums\RepaymentStatus;
use App\Domains\Repayments\Models\Repayment;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\FinancialIntegrityException;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manual bank reconciliation: confirming that Naipay's own records of what
 * moved through a bank account agree with what the bank's statement shows.
 *
 * There is no direct bank feed in this release, so every statement line is
 * an officer's own transcription. Naipay's ledger keeps a single Cash at
 * Bank control account across every bank account combined, so reconciling
 * one specific account is done against that account's own repayments
 * (money in) and loan disbursements (money out) directly, not against the
 * ledger — the two are reconciled to each other by summing every account's
 * activity, which is a Phase 13 reporting concern, not this one's.
 */
final class ReconciliationService
{
    private const MODULE = 'reconciliation';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MakerCheckerGuard $makerChecker,
        private readonly ApprovalNotifier $approvalNotifier,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function open(array $attributes, Staff $actor): BankReconciliation
    {
        return DB::transaction(function () use ($attributes, $actor): BankReconciliation {
            $reconciliation = new BankReconciliation($attributes);
            $reconciliation->status = BankReconciliationStatus::InProgress;
            $reconciliation->prepared_by = $actor->getKey();
            $reconciliation->save();

            $this->audit->recordCreation('reconciliation.opened', self::MODULE, $reconciliation, $actor);

            return $reconciliation->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addLine(BankReconciliation $reconciliation, array $attributes, Staff $actor): BankStatementLine
    {
        $this->assertInProgress($reconciliation);

        return DB::transaction(function () use ($reconciliation, $attributes, $actor): BankStatementLine {
            $line = new BankStatementLine($attributes);
            $line->bank_reconciliation_id = $reconciliation->getKey();
            $line->bank_account_id = $reconciliation->bank_account_id;
            $line->status = BankStatementLineStatus::Unmatched;
            $line->created_by = $actor->getKey();
            $line->save();

            $this->audit->recordCreation('reconciliation.line_added', self::MODULE, $line, $actor);

            return $line->fresh();
        });
    }

    /**
     * Candidate internal records for a line, ordered by how close their own
     * date falls to the statement date — a suggestion, not a filter: an
     * older repayment can genuinely still clear on this statement.
     *
     * @return Collection<int, Repayment>|Collection<int, Loan>
     */
    public function suggestMatches(BankStatementLine $line): Collection
    {
        if ($line->direction === BankStatementLineDirection::Credit) {
            return Repayment::query()
                ->where('receiving_bank_account_id', $line->bank_account_id)
                ->where('amount', $line->amount->toDecimalString())
                ->where('status', RepaymentStatus::Approved->value)
                ->whereNotIn('id', $this->alreadyMatchedIds(Repayment::class))
                ->orderByRaw('ABS(DATEDIFF(payment_date, ?))', [$line->statement_date->toDateString()])
                ->get();
        }

        return Loan::query()
            ->where('disbursement_bank_account_id', $line->bank_account_id)
            ->where('principal_amount', $line->amount->toDecimalString())
            ->whereIn('status', [LoanStatus::Disbursed->value, LoanStatus::WrittenOff->value])
            ->whereNotIn('id', $this->alreadyMatchedIds(Loan::class))
            ->orderByRaw('ABS(DATEDIFF(disbursement_date, ?))', [$line->statement_date->toDateString()])
            ->get();
    }

    public function match(BankStatementLine $line, string $matchedToType, int $matchedToId, Staff $actor): BankStatementLine
    {
        $this->assertInProgress($line->reconciliation()->firstOrFail());

        if ($line->status !== BankStatementLineStatus::Unmatched) {
            throw new DomainException('Only an unmatched line can be matched. Unmatch it first.');
        }

        $target = $this->assertValidMatch($line, $matchedToType, $matchedToId);

        return DB::transaction(function () use ($line, $matchedToType, $target, $actor): BankStatementLine {
            $before = $line->getAttributes();

            $line->forceFill([
                'status' => BankStatementLineStatus::Matched,
                'matched_to_type' => $matchedToType,
                'matched_to_id' => $target->getKey(),
                'matched_by' => $actor->getKey(),
                'matched_at' => now(),
            ])->save();

            $this->audit->recordChange('reconciliation.line_matched', self::MODULE, $line, $before, actor: $actor);

            return $line->fresh();
        });
    }

    public function unmatch(BankStatementLine $line, Staff $actor): BankStatementLine
    {
        $this->assertInProgress($line->reconciliation()->firstOrFail());

        if ($line->status !== BankStatementLineStatus::Matched) {
            throw new DomainException('Only a matched line can be unmatched.');
        }

        return DB::transaction(function () use ($line, $actor): BankStatementLine {
            $before = $line->getAttributes();

            $line->forceFill([
                'status' => BankStatementLineStatus::Unmatched,
                'matched_to_type' => null,
                'matched_to_id' => null,
                'matched_by' => null,
                'matched_at' => null,
            ])->save();

            $this->audit->recordChange('reconciliation.line_unmatched', self::MODULE, $line, $before, actor: $actor);

            return $line->fresh();
        });
    }

    public function exclude(BankStatementLine $line, string $reason, Staff $actor): BankStatementLine
    {
        $this->assertInProgress($line->reconciliation()->firstOrFail());

        if ($line->status !== BankStatementLineStatus::Unmatched) {
            throw new DomainException('Only an unmatched line can be excluded.');
        }

        return DB::transaction(function () use ($line, $reason, $actor): BankStatementLine {
            $before = $line->getAttributes();

            $line->forceFill([
                'status' => BankStatementLineStatus::Excluded,
                'excluded_reason' => $reason,
            ])->save();

            $this->audit->recordChange('reconciliation.line_excluded', self::MODULE, $line, $before, reason: $reason, actor: $actor);

            return $line->fresh();
        });
    }

    /**
     * Submits the reconciliation for approval, once every line is resolved
     * and the statement's own arithmetic checks out.
     */
    public function submit(BankReconciliation $reconciliation, Staff $actor): BankReconciliation
    {
        $this->assertTransition($reconciliation, BankReconciliationStatus::PendingApproval);

        if ($reconciliation->hasUnresolvedLines()) {
            throw new DomainException(
                'Every line must be matched or excluded before this reconciliation can be submitted.',
            );
        }

        $this->assertStatementReconciles($reconciliation);

        return DB::transaction(function () use ($reconciliation, $actor): BankReconciliation {
            $before = $reconciliation->getAttributes();

            $reconciliation->forceFill([
                'status' => BankReconciliationStatus::PendingApproval,
                'submitted_at' => now(),
            ])->save();

            $this->audit->recordChange('reconciliation.submitted', self::MODULE, $reconciliation, $before, actor: $actor);

            return $reconciliation->fresh();
        });
    }

    public function approve(BankReconciliation $reconciliation, Staff $actor): BankReconciliation
    {
        $this->assertTransition($reconciliation, BankReconciliationStatus::Approved);

        $this->makerChecker->assertCanApprove($actor, new BankReconciliationApproval($reconciliation));

        return DB::transaction(function () use ($reconciliation, $actor): BankReconciliation {
            $before = $reconciliation->getAttributes();

            $reconciliation->forceFill([
                'status' => BankReconciliationStatus::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ])->save();

            $this->audit->recordChange('reconciliation.approved', self::MODULE, $reconciliation, $before, actor: $actor);

            $this->approvalNotifier->notifyDecision(
                new BankReconciliationApproval($reconciliation),
                'approved',
                "Reconciliation #{$reconciliation->id}",
                "/reconciliation/{$reconciliation->id}",
                $actor,
                subject: $reconciliation,
            );

            return $reconciliation->fresh();
        });
    }

    private function assertValidMatch(BankStatementLine $line, string $matchedToType, int $matchedToId): Repayment|Loan
    {
        if ($matchedToType === Repayment::class) {
            if ($line->direction !== BankStatementLineDirection::Credit) {
                throw new DomainException('A debit line cannot be matched to a repayment.');
            }

            /** @var Repayment $repayment */
            $repayment = Repayment::query()->findOrFail($matchedToId);

            if ($repayment->receiving_bank_account_id !== $line->bank_account_id) {
                throw new DomainException('This repayment was not received into this bank account.');
            }

            if ($repayment->status !== RepaymentStatus::Approved) {
                throw new DomainException('Only an approved repayment can be matched.');
            }

            if (! $repayment->amount->equals($line->amount)) {
                throw new DomainException('The repayment amount does not match this line.');
            }

            $this->assertNotAlreadyMatched($matchedToType, $matchedToId, $line);

            return $repayment;
        }

        if ($matchedToType === Loan::class) {
            if ($line->direction !== BankStatementLineDirection::Debit) {
                throw new DomainException('A credit line cannot be matched to a loan disbursement.');
            }

            /** @var Loan $loan */
            $loan = Loan::query()->findOrFail($matchedToId);

            if ($loan->disbursement_bank_account_id !== $line->bank_account_id) {
                throw new DomainException('This loan was not disbursed from this bank account.');
            }

            if (! in_array($loan->status, [LoanStatus::Disbursed, LoanStatus::WrittenOff], true)) {
                throw new DomainException('Only a disbursed loan can be matched.');
            }

            if (! $loan->principal_amount->equals($line->amount)) {
                throw new DomainException('The disbursed principal does not match this line.');
            }

            $this->assertNotAlreadyMatched($matchedToType, $matchedToId, $line);

            return $loan;
        }

        throw new DomainException("Unknown match target type [{$matchedToType}].");
    }

    private function assertNotAlreadyMatched(string $matchedToType, int $matchedToId, BankStatementLine $line): void
    {
        $alreadyMatched = BankStatementLine::query()
            ->where('matched_to_type', $matchedToType)
            ->where('matched_to_id', $matchedToId)
            ->where('status', BankStatementLineStatus::Matched->value)
            ->where('id', '!=', $line->getKey())
            ->exists();

        if ($alreadyMatched) {
            throw new DomainException('This record is already matched to a different statement line.');
        }
    }

    /**
     * @return array<int, int>
     */
    private function alreadyMatchedIds(string $type): array
    {
        return BankStatementLine::query()
            ->where('matched_to_type', $type)
            ->where('status', BankStatementLineStatus::Matched->value)
            ->pluck('matched_to_id')
            ->all();
    }

    /**
     * The statement's own transcription must add up: opening balance, plus
     * every credit, minus every debit — matched or excluded, since an
     * excluded line (a bank fee, interest earned) still really happened —
     * must equal the closing balance the officer read off the statement.
     */
    private function assertStatementReconciles(BankReconciliation $reconciliation): void
    {
        $currency = $reconciliation->statement_opening_balance->currency();
        $movement = Money::zero($currency);

        foreach ($reconciliation->lines as $line) {
            $movement = $line->direction === BankStatementLineDirection::Credit
                ? $movement->plus($line->amount)
                : $movement->minus($line->amount);
        }

        $expectedClosing = $reconciliation->statement_opening_balance->plus($movement);

        if (! $expectedClosing->equals($reconciliation->statement_closing_balance)) {
            throw new FinancialIntegrityException(
                'The statement lines do not reconcile the opening balance to the closing balance.',
                [
                    'expected_closing' => $expectedClosing->toDecimalString(),
                    'stated_closing' => $reconciliation->statement_closing_balance->toDecimalString(),
                ],
            );
        }
    }

    private function assertInProgress(BankReconciliation $reconciliation): void
    {
        if ($reconciliation->status !== BankReconciliationStatus::InProgress) {
            throw new DomainException(
                "This reconciliation is {$reconciliation->status->label()} and can no longer be changed.",
            );
        }
    }

    private function assertTransition(BankReconciliation $reconciliation, BankReconciliationStatus $target): void
    {
        if (! $reconciliation->status->canTransitionTo($target)) {
            throw new DomainException(
                "A reconciliation that is {$reconciliation->status->label()} cannot move to {$target->label()}.",
            );
        }
    }
}
