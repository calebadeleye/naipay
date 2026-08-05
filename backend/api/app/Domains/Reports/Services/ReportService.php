<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Businesses\Enums\VerificationStatus;
use App\Domains\Businesses\Models\Business;
use App\Domains\Ledger\Enums\AccountType;
use App\Domains\Ledger\Enums\DebitCredit;
use App\Domains\LoanApplications\Enums\LoanApplicationStatus;
use App\Domains\LoanApplications\Models\LoanApplication;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use App\Domains\Merchants\Enums\KycStatus;
use App\Domains\Merchants\Enums\MerchantStatus;
use App\Domains\Merchants\Enums\OnboardingStatus;
use App\Domains\Merchants\Models\Merchant;
use App\Domains\Repayments\Enums\RepaymentStatus;
use App\Domains\Repayments\Models\Repayment;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only reporting over data every other domain already owns.
 *
 * Nothing here writes anything, and nothing here is itself a source of
 * truth — the trial balance is derived from journal_entries exactly the way
 * AccountBalance's own docblock says a reconciliation should, rather than
 * from the cached account_balances table, so a report as of a past date is
 * as trustworthy as one for today.
 */
final class ReportService
{
    public function dashboard(): array
    {
        return [
            'merchants' => [
                'total' => Merchant::query()->count(),
                'active' => Merchant::query()->where('merchant_status', MerchantStatus::Active->value)->count(),
                'pending_onboarding' => Merchant::query()->whereNotIn('onboarding_status', [
                    OnboardingStatus::Approved->value,
                    OnboardingStatus::Rejected->value,
                    OnboardingStatus::Closed->value,
                ])->count(),
            ],

            'loan_applications' => $this->countsByStatus(
                LoanApplication::query(),
                'status',
                array_map(static fn (LoanApplicationStatus $s): string => $s->value, LoanApplicationStatus::cases()),
            ),

            'loans' => array_merge(
                $this->countsByStatus(Loan::query(), 'status', array_map(static fn (LoanStatus $s): string => $s->value, LoanStatus::cases())),
                ['total_outstanding_principal' => $this->sumMoney(Loan::query()->where('status', LoanStatus::Disbursed->value), 'outstanding_principal')->toDecimalString()],
            ),

            'repayments' => [
                'pending_verification' => Repayment::query()->where('status', RepaymentStatus::Recorded->value)->count(),
                'pending_approval' => Repayment::query()->where('status', RepaymentStatus::Verified->value)->count(),
                'collected_this_month' => $this->sumMoney(
                    Repayment::query()
                        ->where('status', RepaymentStatus::Approved->value)
                        ->whereBetween('payment_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]),
                    'amount',
                )->toDecimalString(),
            ],

            'portfolio_at_risk' => $this->delinquency()['portfolio_at_risk'],
        ];
    }

    /**
     * As of the given date (defaulting to today), derived from journal
     * entries directly rather than the account_balances cache — a trial
     * balance is exactly the reconciliation that cache exists to be checked
     * against.
     *
     * @return array<string, mixed>
     */
    public function trialBalance(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();

        $rows = DB::table('journal_entries')
            ->join('journal_transactions', 'journal_transactions.id', '=', 'journal_entries.journal_transaction_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_entries.ledger_account_id')
            ->where('journal_transactions.posting_date', '<=', $asOf->toDateString())
            ->groupBy('ledger_accounts.id', 'ledger_accounts.code', 'ledger_accounts.name', 'ledger_accounts.type')
            ->orderBy('ledger_accounts.code')
            ->select([
                'ledger_accounts.id',
                'ledger_accounts.code',
                'ledger_accounts.name',
                'ledger_accounts.type',
                DB::raw('SUM(journal_entries.debit_amount) as total_debits'),
                DB::raw('SUM(journal_entries.credit_amount) as total_credits'),
            ])
            ->get();

        $accounts = [];
        $grandDebits = Money::zero();
        $grandCredits = Money::zero();

        foreach ($rows as $row) {
            $debits = Money::fromDecimal((string) $row->total_debits);
            $credits = Money::fromDecimal((string) $row->total_credits);
            $type = AccountType::from($row->type);

            $balance = $debits->minus($credits);
            $balance = $type->normalBalance() === DebitCredit::Credit ? $balance->negated() : $balance;

            $accounts[] = [
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'total_debits' => $debits->toDecimalString(),
                'total_credits' => $credits->toDecimalString(),
                'balance' => $balance->toDecimalString(),
            ];

            $grandDebits = $grandDebits->plus($debits);
            $grandCredits = $grandCredits->plus($credits);
        }

        return [
            'as_of' => $asOf->toDateString(),
            'accounts' => $accounts,
            'total_debits' => $grandDebits->toDecimalString(),
            'total_credits' => $grandCredits->toDecimalString(),
            // Every posting is balanced at the point it is made — see
            // LedgerPostingService::assertBalanced() — so this must always
            // be true. Surfaced here as the report's own self-check.
            'is_balanced' => $grandDebits->equals($grandCredits),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loanPortfolio(): array
    {
        $byStatus = $this->groupWithOutstanding('status', array_map(static fn (LoanStatus $s): string => $s->value, LoanStatus::cases()));
        $byProduct = DB::table('loans')
            ->join('loan_products', 'loan_products.id', '=', 'loans.loan_product_id')
            ->where('loans.status', LoanStatus::Disbursed->value)
            ->groupBy('loan_products.id', 'loan_products.name')
            ->select(['loan_products.id', 'loan_products.name', DB::raw('COUNT(*) as count'), DB::raw('SUM(loans.outstanding_principal) as outstanding')])
            ->get()
            ->map(fn ($row): array => [
                'loan_product_id' => $row->id,
                'name' => $row->name,
                'count' => (int) $row->count,
                'outstanding_principal' => Money::fromDecimal((string) $row->outstanding)->toDecimalString(),
            ])
            ->all();

        return [
            'by_status' => $byStatus,
            'by_product' => $byProduct,
            'total_outstanding_principal' => $this->sumMoney(Loan::query()->where('status', LoanStatus::Disbursed->value), 'outstanding_principal')->toDecimalString(),
        ];
    }

    /**
     * Approved repayments within a period, and how they were allocated.
     *
     * @return array<string, mixed>
     */
    public function collections(Carbon $from, Carbon $to): array
    {
        $query = Repayment::query()
            ->where('status', RepaymentStatus::Approved->value)
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()]);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'count' => (clone $query)->count(),
            'total_collected' => $this->sumMoney(clone $query, 'amount')->toDecimalString(),
            'allocated_principal' => $this->sumMoney(clone $query, 'allocated_principal')->toDecimalString(),
            'allocated_interest' => $this->sumMoney(clone $query, 'allocated_interest')->toDecimalString(),
            'allocated_fee' => $this->sumMoney(clone $query, 'allocated_fee')->toDecimalString(),
            'allocated_excess' => $this->sumMoney(clone $query, 'allocated_excess')->toDecimalString(),
            'allocated_unallocated' => $this->sumMoney(clone $query, 'allocated_unallocated')->toDecimalString(),
        ];
    }

    /**
     * Every disbursed loan, bucketed by how many days its earliest unpaid
     * instalment has been overdue, per naipay.loans.ageing_buckets — and
     * the portfolio at risk beyond naipay.loans.par_threshold_days.
     *
     * @return array<string, mixed>
     */
    public function delinquency(): array
    {
        $today = Carbon::today();

        $overdueLoans = DB::table('loan_schedule_entries')
            ->join('loans', 'loans.id', '=', 'loan_schedule_entries.loan_id')
            ->where('loans.status', LoanStatus::Disbursed->value)
            ->where('loan_schedule_entries.due_date', '<', $today->toDateString())
            ->whereRaw(
                '(loan_schedule_entries.principal_paid + loan_schedule_entries.interest_paid + loan_schedule_entries.fee_paid) '
                .'< (loan_schedule_entries.principal_due + loan_schedule_entries.interest_due + loan_schedule_entries.fee_due)',
            )
            ->groupBy('loan_schedule_entries.loan_id', 'loans.outstanding_principal')
            ->select([
                'loan_schedule_entries.loan_id',
                'loans.outstanding_principal',
                DB::raw('MIN(loan_schedule_entries.due_date) as earliest_overdue_due_date'),
            ])
            ->get();

        /** @var array<int, string> $bucketLabels */
        $bucketLabels = [];
        $bucketCounts = [];
        $bucketAmounts = [];

        foreach (config('naipay.loans.ageing_buckets', []) as $bucket) {
            $bucketLabels[] = $bucket['label'];
            $bucketCounts[$bucket['label']] = 0;
            $bucketAmounts[$bucket['label']] = Money::zero();
        }

        $parThresholdDays = (int) config('naipay.loans.par_threshold_days', 30);
        $parOutstanding = Money::zero();
        $overdueOutstanding = Money::zero();

        foreach ($overdueLoans as $row) {
            // diffInDays is signed by direction of the call; the due date is
            // always in the past here, so the absolute value is what "days
            // overdue" means.
            $daysOverdue = abs($today->diffInDays(Carbon::parse($row->earliest_overdue_due_date)));
            $outstanding = Money::fromDecimal((string) $row->outstanding_principal);

            $overdueOutstanding = $overdueOutstanding->plus($outstanding);

            foreach (config('naipay.loans.ageing_buckets', []) as $bucket) {
                $inBucket = $daysOverdue >= $bucket['from'] && ($bucket['to'] === null || $daysOverdue <= $bucket['to']);

                if ($inBucket) {
                    $bucketCounts[$bucket['label']]++;
                    $bucketAmounts[$bucket['label']] = $bucketAmounts[$bucket['label']]->plus($outstanding);

                    break;
                }
            }

            if ($daysOverdue >= $parThresholdDays) {
                $parOutstanding = $parOutstanding->plus($outstanding);
            }
        }

        $totalOutstanding = $this->sumMoney(Loan::query()->where('status', LoanStatus::Disbursed->value), 'outstanding_principal');
        $currentOutstanding = $totalOutstanding->minus($overdueOutstanding);
        $totalDisbursedLoans = Loan::query()->where('status', LoanStatus::Disbursed->value)->count();

        $buckets = [];

        foreach ($bucketLabels as $label) {
            $buckets[] = [
                'label' => $label,
                'count' => $bucketCounts[$label],
                'outstanding_principal' => $bucketAmounts[$label]->toDecimalString(),
            ];
        }

        return [
            'current' => [
                'count' => $totalDisbursedLoans - $overdueLoans->count(),
                'outstanding_principal' => $currentOutstanding->toDecimalString(),
            ],
            'ageing_buckets' => $buckets,
            'portfolio_at_risk' => [
                'threshold_days' => $parThresholdDays,
                'outstanding_principal' => $parOutstanding->toDecimalString(),
                'percentage_of_portfolio' => $totalOutstanding->isPositive()
                    ? round((float) $parOutstanding->toDecimalString() / (float) $totalOutstanding->toDecimalString() * 100, 2)
                    : 0.0,
            ],
        ];
    }

    /**
     * Merchants and businesses still awaiting a KYC or verification
     * decision, and how long each has been waiting — the ageing itself is
     * the compliance signal, not any single count.
     *
     * @return array<string, mixed>
     */
    public function complianceOverview(): array
    {
        $pendingMerchants = Merchant::query()
            ->whereIn('kyc_status', [KycStatus::NotStarted->value, KycStatus::Pending->value])
            ->get(['id', 'merchant_number', 'first_name', 'last_name', 'kyc_status', 'submitted_at']);

        $pendingBusinesses = Business::query()
            ->where('verification_status', VerificationStatus::Pending->value)
            ->get(['id', 'business_name', 'verification_status', 'created_at']);

        $today = Carbon::today();

        return [
            'pending_kyc' => [
                'count' => $pendingMerchants->count(),
                'merchants' => $pendingMerchants->map(fn (Merchant $m): array => [
                    'id' => $m->id,
                    'merchant_number' => $m->merchant_number,
                    'full_name' => trim("{$m->first_name} {$m->last_name}"),
                    'kyc_status' => $m->kyc_status->value,
                    'days_waiting' => $m->submitted_at !== null ? (int) abs($today->diffInDays($m->submitted_at)) : null,
                ])->all(),
            ],
            'pending_business_verification' => [
                'count' => $pendingBusinesses->count(),
                'businesses' => $pendingBusinesses->map(fn (Business $b): array => [
                    'id' => $b->id,
                    'business_name' => $b->business_name,
                    'days_waiting' => (int) abs($today->diffInDays($b->created_at)),
                ])->all(),
            ],
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     */
    private function sumMoney($query, string $column): Money
    {
        return Money::fromDecimal((string) ($query->sum($column) ?: '0'));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  array<int, string>  $values
     * @return array<string, int>
     */
    private function countsByStatus($query, string $column, array $values): array
    {
        $counts = (clone $query)->groupBy($column)->select($column, DB::raw('COUNT(*) as aggregate'))->pluck('aggregate', $column);

        $result = [];

        foreach ($values as $value) {
            $result[$value] = (int) ($counts[$value] ?? 0);
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, array<string, mixed>>
     */
    private function groupWithOutstanding(string $column, array $values): array
    {
        $rows = Loan::query()
            ->groupBy($column)
            ->select($column, DB::raw('COUNT(*) as aggregate'), DB::raw('SUM(outstanding_principal) as outstanding'))
            ->get()
            ->keyBy($column);

        $result = [];

        foreach ($values as $value) {
            $row = $rows[$value] ?? null;

            $result[] = [
                'status' => $value,
                'count' => $row !== null ? (int) $row->aggregate : 0,
                'outstanding_principal' => $row !== null && $row->outstanding !== null
                    ? Money::fromDecimal((string) $row->outstanding)->toDecimalString()
                    : '0.00',
            ];
        }

        return $result;
    }
}
