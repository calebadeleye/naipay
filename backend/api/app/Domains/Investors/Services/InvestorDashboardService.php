<?php

declare(strict_types=1);

namespace App\Domains\Investors\Services;

use App\Domains\Branches\Models\Branch;
use App\Domains\Ledger\Enums\AccountType;
use App\Domains\Ledger\Enums\DebitCredit;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use App\Domains\Reports\Services\ReportService;
use App\Domains\Repayments\Enums\RepaymentStatus;
use App\Domains\Repayments\Models\Repayment;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the figures an investor is shown: portfolio value, revenue and
 * profit, ROI, collections and branch performance.
 *
 * Nothing here is a new source of truth — every figure is derived from the
 * ledger and loan/repayment data the rest of the system already owns, the
 * same way ReportService's reports are, and several sections wrap
 * ReportService directly rather than re-deriving what it already computes.
 */
final class InvestorDashboardService
{
    public function __construct(
        private readonly ReportService $reports,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();
        $yearStart = $today->copy()->startOfYear();

        $loanPortfolio = $this->reports->loanPortfolio();
        $delinquency = $this->reports->delinquency();
        $collectionsMtd = $this->reports->collections($monthStart, $today);

        $totalOutstandingPrincipal = Money::fromDecimal($loanPortfolio['total_outstanding_principal']);
        $totalAssets = $this->sumAccountBalances(AccountType::Asset, $today);

        $revenueMtd = $this->netIncomeAccountActivity(AccountType::Income, $monthStart, $today);
        $expensesMtd = $this->netIncomeAccountActivity(AccountType::Expense, $monthStart, $today);
        $netProfitMtd = $revenueMtd->minus($expensesMtd);

        $revenueYtd = $this->netIncomeAccountActivity(AccountType::Income, $yearStart, $today);
        $expensesYtd = $this->netIncomeAccountActivity(AccountType::Expense, $yearStart, $today);
        $netProfitYtd = $revenueYtd->minus($expensesYtd);

        $roiYtd = $totalAssets->isPositive()
            ? round(((float) $netProfitYtd->toDecimalString() / (float) $totalAssets->toDecimalString()) * 100, 2)
            : 0.0;

        $activeLoans = Loan::query()->where('status', LoanStatus::Disbursed->value)->count();
        $numberOfDebtors = Loan::query()
            ->where('status', LoanStatus::Disbursed->value)
            ->distinct('merchant_id')
            ->count('merchant_id');

        $growth = $this->portfolioGrowth($yearStart, $totalOutstandingPrincipal);

        return [
            'as_of' => $today->toDateString(),

            'total_investment_portfolio' => $totalAssets->toDecimalString(),
            'total_revenue_mtd' => $revenueMtd->toDecimalString(),
            'net_profit_mtd' => $netProfitMtd->toDecimalString(),
            'roi_ytd_percentage' => $roiYtd,
            'number_of_debtors' => $numberOfDebtors,

            'active_loans' => $activeLoans,
            'outstanding_loan_balance' => $totalOutstandingPrincipal->toDecimalString(),
            'collections_this_month' => $collectionsMtd['total_collected'],
            'default_rate_percentage' => $delinquency['portfolio_at_risk']['percentage_of_portfolio'],
            'portfolio_growth_ytd_percentage' => $growth,

            'revenue_and_profit_trend' => $this->revenueAndProfitTrend(6),
            'portfolio_allocation' => $this->portfolioAllocation($loanPortfolio, $totalOutstandingPrincipal),
            'top_performing_branches' => $this->topPerformingBranches($monthStart, $today),
            'recent_repayments' => $this->recentRepayments(10),
        ];
    }

    /**
     * The balance of every account of a given type, as of a date — the same
     * derivation ReportService::trialBalance() uses, restricted to one type
     * and summed rather than broken out per account.
     */
    private function sumAccountBalances(AccountType $type, Carbon $asOf): Money
    {
        $row = DB::table('journal_entries')
            ->join('journal_transactions', 'journal_transactions.id', '=', 'journal_entries.journal_transaction_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_entries.ledger_account_id')
            ->where('ledger_accounts.type', $type->value)
            ->where('journal_transactions.posting_date', '<=', $asOf->toDateString())
            ->selectRaw('SUM(journal_entries.debit_amount) as total_debits, SUM(journal_entries.credit_amount) as total_credits')
            ->first();

        $debits = Money::fromDecimal((string) ($row->total_debits ?? '0'));
        $credits = Money::fromDecimal((string) ($row->total_credits ?? '0'));

        $balance = $debits->minus($credits);

        return $type->normalBalance() === DebitCredit::Credit ? $balance->negated() : $balance;
    }

    /**
     * Activity posted to accounts of a given type within a date range, on
     * the normal-balance side — i.e. income earned or expense incurred in
     * the period, not the account's running balance.
     */
    private function netIncomeAccountActivity(AccountType $type, Carbon $from, Carbon $to): Money
    {
        $row = DB::table('journal_entries')
            ->join('journal_transactions', 'journal_transactions.id', '=', 'journal_entries.journal_transaction_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_entries.ledger_account_id')
            ->where('ledger_accounts.type', $type->value)
            ->whereBetween('journal_transactions.posting_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('SUM(journal_entries.debit_amount) as total_debits, SUM(journal_entries.credit_amount) as total_credits')
            ->first();

        $debits = Money::fromDecimal((string) ($row->total_debits ?? '0'));
        $credits = Money::fromDecimal((string) ($row->total_credits ?? '0'));

        $activity = $debits->minus($credits);

        return $type->normalBalance() === DebitCredit::Credit ? $activity->negated() : $activity;
    }

    /**
     * Revenue (income account activity) and net profit for each of the last
     * `$months` calendar months, oldest first — the series behind the trend
     * chart.
     *
     * @return array<int, array<string, mixed>>
     */
    private function revenueAndProfitTrend(int $months): array
    {
        $series = [];
        $cursor = Carbon::today()->startOfMonth()->subMonths($months - 1);

        for ($i = 0; $i < $months; $i++) {
            $monthStart = $cursor->copy();
            $monthEnd = $cursor->copy()->endOfMonth();

            $revenue = $this->netIncomeAccountActivity(AccountType::Income, $monthStart, $monthEnd);
            $expenses = $this->netIncomeAccountActivity(AccountType::Expense, $monthStart, $monthEnd);

            $series[] = [
                'period' => $monthStart->format('Y-m'),
                'label' => $monthStart->format('M \'y'),
                'revenue' => $revenue->toDecimalString(),
                'net_profit' => $revenue->minus($expenses)->toDecimalString(),
            ];

            $cursor->addMonth();
        }

        return $series;
    }

    /**
     * Outstanding loan principal by product, as a share of the portfolio —
     * reshapes ReportService::loanPortfolio()'s `by_product` breakdown
     * rather than re-querying it.
     *
     * @param  array<string, mixed>  $loanPortfolio
     * @return array<int, array<string, mixed>>
     */
    private function portfolioAllocation(array $loanPortfolio, Money $totalOutstandingPrincipal): array
    {
        $total = (float) $totalOutstandingPrincipal->toDecimalString();

        return array_map(static function (array $product) use ($total): array {
            $outstanding = (float) $product['outstanding_principal'];

            return [
                'name' => $product['name'],
                'outstanding_principal' => $product['outstanding_principal'],
                'percentage' => $total > 0 ? round($outstanding / $total * 100, 1) : 0.0,
            ];
        }, $loanPortfolio['by_product']);
    }

    /**
     * The five branches with the largest disbursed loan portfolio, and how
     * much of that portfolio was disbursed this month — the closest
     * available proxy for month-on-month growth without a historical
     * portfolio snapshot.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topPerformingBranches(Carbon $monthStart, Carbon $today): array
    {
        $rows = Loan::query()
            ->where('status', LoanStatus::Disbursed->value)
            ->whereNotNull('branch_id')
            ->groupBy('branch_id')
            ->select('branch_id', DB::raw('SUM(outstanding_principal) as outstanding'), DB::raw('COUNT(*) as count'))
            ->orderByDesc('outstanding')
            ->limit(5)
            ->get()
            ->keyBy('branch_id');

        if ($rows->isEmpty()) {
            return [];
        }

        $branches = Branch::query()->whereIn('id', $rows->keys())->get()->keyBy('id');

        $disbursedThisMonth = Loan::query()
            ->whereIn('branch_id', $rows->keys())
            ->whereNotNull('disbursed_at')
            ->whereBetween('disbursed_at', [$monthStart->toDateTimeString(), $today->endOfDay()->toDateTimeString()])
            ->groupBy('branch_id')
            ->select('branch_id', DB::raw('SUM(principal_amount) as disbursed'))
            ->pluck('disbursed', 'branch_id');

        return $rows->map(function ($row) use ($branches, $disbursedThisMonth): array {
            $branch = $branches->get($row->branch_id);
            $outstanding = Money::fromDecimal((string) $row->outstanding);
            $disbursedMtd = Money::fromDecimal((string) ($disbursedThisMonth[$row->branch_id] ?? '0'));

            return [
                'branch_id' => $row->branch_id,
                'name' => $branch?->name ?? 'Unassigned',
                'branch_code' => $branch?->branch_code,
                'credit_portfolio' => $outstanding->toDecimalString(),
                'loan_count' => (int) $row->count,
                'growth_mtd_percentage' => $outstanding->isPositive()
                    ? round((float) $disbursedMtd->toDecimalString() / (float) $outstanding->toDecimalString() * 100, 1)
                    : 0.0,
            ];
        })->values()->all();
    }

    /**
     * The most recent approved repayments — only what an outside party
     * needs to see: who paid, how much, and when.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentRepayments(int $limit): array
    {
        return Repayment::query()
            ->where('status', RepaymentStatus::Approved->value)
            ->with('merchant')
            ->latest('payment_date')
            ->limit($limit)
            ->get()
            ->map(fn (Repayment $repayment): array => [
                'id' => $repayment->id,
                'merchant_name' => $repayment->merchant?->fullName() ?? 'Unknown',
                'amount' => $repayment->amount->toDecimalString(),
                'payment_date' => $repayment->payment_date?->toDateString(),
            ])
            ->all();
    }

    /**
     * Growth of the outstanding loan portfolio so far this year, expressed
     * as new principal disbursed since 1 January against the portfolio
     * carried over from before then. There is no historical portfolio
     * snapshot to diff against directly, so this is the closest available
     * proxy for "how much bigger is the book than it started the year".
     */
    private function portfolioGrowth(Carbon $yearStart, Money $totalOutstandingPrincipal): float
    {
        $disbursedYtd = Money::fromDecimal((string) (
            Loan::query()
                ->whereNotNull('disbursed_at')
                ->where('disbursed_at', '>=', $yearStart->toDateTimeString())
                ->sum('principal_amount') ?: '0'
        ));

        $baseAtStartOfYear = $totalOutstandingPrincipal->minus($disbursedYtd);

        if (! $baseAtStartOfYear->isPositive()) {
            return 0.0;
        }

        return round((float) $disbursedYtd->toDecimalString() / (float) $baseAtStartOfYear->toDecimalString() * 100, 1);
    }
}
