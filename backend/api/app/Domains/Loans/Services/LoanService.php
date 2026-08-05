<?php

declare(strict_types=1);

namespace App\Domains\Loans\Services;

use App\Domains\Approvals\Services\MakerCheckerGuard;
use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Data\JournalLine;
use App\Domains\Ledger\Services\LedgerPostingService;
use App\Domains\Ledger\Support\StandardAccounts;
use App\Domains\Loans\Approvals\LoanApproval;
use App\Domains\Loans\Approvals\LoanWriteOffApproval;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use App\Support\Exceptions\DomainException;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Confirms and, if it comes to it, writes off a loan.
 *
 * Disbursement lives in its own service — LoanDisbursementService — because
 * it carries a genuinely different weight: it is the one action here that
 * moves real money and builds the repayment schedule, the same reasoning
 * that keeps LedgerPostingService separate from BankAccountService.
 */
final class LoanService
{
    private const MODULE = 'loans';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MakerCheckerGuard $makerChecker,
        private readonly LedgerPostingService $ledger,
    ) {}

    /**
     * The second sign-off: a different officer confirms the loan contract
     * LoanCreationService generated is correct and ready to fund.
     */
    public function approve(Loan $loan, Staff $actor): Loan
    {
        $this->assertTransition($loan, LoanStatus::PendingDisbursement);

        $this->makerChecker->assertCanApprove($actor, new LoanApproval($loan));

        return DB::transaction(function () use ($loan, $actor): Loan {
            $before = $loan->getAttributes();

            $loan->forceFill([
                'status' => LoanStatus::PendingDisbursement,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ])->save();

            $this->audit->recordChange('loan.approved', self::MODULE, $loan, $before, actor: $actor);

            return $loan->fresh();
        });
    }

    /**
     * Writes off a disbursed loan's outstanding principal as unrecoverable.
     *
     * Only meaningful once money is actually at risk, so this is refused
     * before disbursement — an undisbursed loan is simply never disbursed,
     * nothing to write off. Interest and fees are not booked to the ledger
     * until they are recognised (Phase 9), so only the principal receivable
     * moves here.
     */
    public function writeOff(Loan $loan, string $reason, Staff $actor): Loan
    {
        if ($loan->status !== LoanStatus::Disbursed) {
            throw new DomainException(
                "A loan that is {$loan->status->label()} cannot be written off.",
            );
        }

        $this->makerChecker->assertCanApprove($actor, new LoanWriteOffApproval($loan));

        return DB::transaction(function () use ($loan, $reason, $actor): Loan {
            $before = $loan->getAttributes();

            $outstanding = $loan->outstanding_principal ?? Money::zero();

            if ($outstanding->isPositive()) {
                $this->ledger->post(
                    transactionType: 'loan_write_off',
                    description: "Write-off of {$loan->loan_reference}: {$reason}",
                    lines: [
                        JournalLine::debit(StandardAccounts::WRITTEN_OFF_LOANS, $outstanding, loanId: $loan->getKey()),
                        JournalLine::credit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, $outstanding, loanId: $loan->getKey()),
                    ],
                    sourceType: Loan::class,
                    sourceId: $loan->getKey(),
                    idempotencyKey: "loan-write-off:{$loan->getKey()}",
                    actor: $actor,
                );
            }

            $loan->forceFill([
                'status' => LoanStatus::WrittenOff,
                'outstanding_principal' => Money::zero(),
                'written_off_by' => $actor->getKey(),
                'written_off_at' => now(),
                'write_off_reason' => $reason,
            ])->save();

            $this->audit->recordChange('loan.written_off', self::MODULE, $loan, $before, reason: $reason, actor: $actor);

            return $loan->fresh();
        });
    }

    private function assertTransition(Loan $loan, LoanStatus $target): void
    {
        if (! $loan->status->canTransitionTo($target)) {
            throw new DomainException(
                "A loan that is {$loan->status->label()} cannot move to {$target->label()}.",
            );
        }
    }
}
