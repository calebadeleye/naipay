<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Http\Controllers\Merchant;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Loans\Enums\LoanScheduleEntryStatus;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Http\ApiResponse;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a merchant owes and where to pay it — a read-only summary, like an
 * invoice.
 *
 * There is no payment gateway in this system: a repayment is recorded by a
 * staff member who has confirmed the money actually arrived in the bank, then
 * separately verified and approved. The portal has no "pay now" action —
 * this endpoint only tells the merchant their outstanding balance, when the
 * next instalment is due, and which of Every Merchant's bank accounts to send
 * it to.
 */
final class RepaymentSummaryController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        $loans = Loan::query()
            ->where('merchant_id', $merchant->id)
            ->where('status', LoanStatus::Disbursed)
            ->with(['scheduleEntries' => fn ($query) => $query->orderBy('due_date')])
            ->get();

        $outstanding = Money::zero();
        $nextDue = null;

        foreach ($loans as $loan) {
            $outstanding = $outstanding
                ->plus($loan->outstanding_principal)
                ->plus($loan->outstanding_interest)
                ->plus($loan->outstanding_fees);

            $entry = $loan->scheduleEntries
                ->whereNotIn('status', [LoanScheduleEntryStatus::Paid])
                ->first();

            if ($entry === null) {
                continue;
            }

            if ($nextDue === null || $entry->due_date->lt($nextDue['due_date'])) {
                $nextDue = [
                    'loan_id' => $loan->id,
                    'loan_reference' => $loan->loan_reference,
                    'due_date' => $entry->due_date,
                    'amount' => $entry->principal_due->plus($entry->interest_due)->plus($entry->fee_due),
                    'status' => $entry->status->value,
                ];
            }
        }

        $collectionAccount = BankAccount::query()
            ->active()
            ->forPurpose(BankAccountPurpose::LoanRepaymentCollection)
            ->where('is_default_collection_account', true)
            ->first();

        return ApiResponse::success([
            'outstanding_balance' => $outstanding->jsonSerialize(),
            'active_loan_count' => $loans->count(),
            'next_due' => $nextDue !== null ? [
                'loan_id' => $nextDue['loan_id'],
                'loan_reference' => $nextDue['loan_reference'],
                'due_date' => $nextDue['due_date']->toDateString(),
                'amount' => $nextDue['amount']->jsonSerialize(),
                'status' => $nextDue['status'],
            ] : null,
            'pay_into' => $collectionAccount !== null ? [
                'bank_name' => $collectionAccount->bank_name,
                'account_name' => $collectionAccount->account_name,
                'account_number' => $collectionAccount->account_number,
            ] : null,
        ], 'Repayment summary retrieved.');
    }
}
