<?php

declare(strict_types=1);

namespace App\Domains\Loans\Services;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Approvals\Services\ApprovalNotifier;
use App\Domains\Approvals\Services\MakerCheckerGuard;
use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Data\JournalLine;
use App\Domains\Ledger\Services\LedgerPostingService;
use App\Domains\Ledger\Support\StandardAccounts;
use App\Domains\LoanProducts\Data\Instalment;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\LoanProducts\Services\LoanCalculator;
use App\Domains\Loans\Approvals\LoanDisbursementApproval;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Models\LoanScheduleEntry;
use App\Domains\Loans\Notifications\LoanDisbursedNotification;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Releases a loan's funds.
 *
 * The one action in this domain that moves real money, so it is where the
 * repayment schedule is finally built (the due dates depend on the
 * disbursement date, which is not known any earlier), the ledger is posted,
 * and the loan's outstanding balances are set — all inside one transaction,
 * per the brief's rule that a balance never changes without a matching ledger
 * entry landing at the same time.
 *
 * Interest and fees are not booked to the ledger here: this system recognises
 * them as they are allocated against a repayment (Phase 9), not upfront at
 * disbursement. `total_interest` and `total_fees` are stored on the loan for
 * display and reporting only.
 */
final class LoanDisbursementService
{
    private const MODULE = 'loans';

    public function __construct(
        private readonly LoanCalculator $calculator,
        private readonly LedgerPostingService $ledger,
        private readonly AuditLogger $audit,
        private readonly MakerCheckerGuard $makerChecker,
        private readonly ApprovalNotifier $approvalNotifier,
    ) {}

    public function disburse(Loan $loan, BankAccount $bankAccount, Staff $actor, ?Carbon $disbursementDate = null): Loan
    {
        if ($loan->status !== LoanStatus::PendingDisbursement) {
            throw new DomainException(
                "A loan that is {$loan->status->label()} cannot be disbursed.",
            );
        }

        $this->makerChecker->assertCanApprove($actor, new LoanDisbursementApproval($loan));
        $this->makerChecker->assertRecentlyReauthenticated($actor, 'loan.disburse');

        if (! $bankAccount->canTransact()) {
            throw new DomainException(
                "{$bankAccount->label()} is not approved for use and cannot fund a disbursement.",
            );
        }

        if (! $bankAccount->hasPurpose(BankAccountPurpose::LoanDisbursement)) {
            throw new DomainException(
                "{$bankAccount->label()} is not designated for loan disbursement.",
            );
        }

        $disbursementDate ??= Carbon::today();

        /** @var LoanProduct $product */
        $product = $loan->loanProduct()->firstOrFail();

        $terms = $product->termsFor($loan->principal_amount, $loan->tenor, $disbursementDate);
        $schedule = $this->calculator->schedule($terms);

        return DB::transaction(function () use ($loan, $bankAccount, $actor, $disbursementDate, $schedule): Loan {
            $before = $loan->getAttributes();

            $transaction = $this->ledger->post(
                transactionType: 'loan_disbursement',
                description: "Disbursement of {$loan->loan_reference} via {$bankAccount->label()}.",
                lines: [
                    JournalLine::debit(
                        StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE,
                        $loan->principal_amount,
                        merchantId: $loan->merchant_id,
                        businessId: $loan->business_id,
                        loanId: $loan->getKey(),
                    ),
                    JournalLine::credit(
                        StandardAccounts::CASH_AT_BANK,
                        $loan->principal_amount,
                        merchantId: $loan->merchant_id,
                        businessId: $loan->business_id,
                        loanId: $loan->getKey(),
                    ),
                ],
                sourceType: Loan::class,
                sourceId: $loan->getKey(),
                idempotencyKey: "loan-disbursement:{$loan->getKey()}",
                actor: $actor,
            );

            foreach ($schedule->instalments as $instalment) {
                /** @var Instalment $instalment */
                LoanScheduleEntry::create([
                    'loan_id' => $loan->getKey(),
                    'installment_number' => $instalment->number,
                    'due_date' => $instalment->dueDate,
                    'opening_principal' => $instalment->openingPrincipal,
                    'principal_due' => $instalment->principalDue,
                    'interest_due' => $instalment->interestDue,
                    'fee_due' => $instalment->feeDue,
                ]);
            }

            $loan->forceFill([
                'status' => LoanStatus::Disbursed,
                'total_interest' => $schedule->totalInterest,
                'total_fees' => $schedule->totalFees,
                'total_payable' => $schedule->totalPayable(),
                'outstanding_principal' => $loan->principal_amount,
                'outstanding_interest' => $schedule->totalInterest,
                'outstanding_fees' => $schedule->totalFees,
                'disbursement_bank_account_id' => $bankAccount->getKey(),
                'disbursement_date' => $disbursementDate,
                'first_repayment_date' => $schedule->firstRepaymentDate(),
                'maturity_date' => $schedule->maturityDate(),
                'disbursement_journal_transaction_id' => $transaction->getKey(),
                'disbursed_by' => $actor->getKey(),
                'disbursed_at' => now(),
            ])->save();

            $this->audit->recordChange('loan.disbursed', self::MODULE, $loan, $before, actor: $actor);

            $this->approvalNotifier->notifyDecision(
                new LoanDisbursementApproval($loan),
                'disbursed',
                $loan->loan_reference,
                "/loans/{$loan->id}",
                $actor,
                subject: $loan,
            );

            $loan = $loan->fresh(['scheduleEntries', 'merchant']);
            $loan->merchant?->notify(new LoanDisbursedNotification($loan));

            return $loan;
        });
    }
}
