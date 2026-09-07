<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Branches\Concerns\BelongsToBranch;
use App\Domains\Identity\Enums\AccessScope;
use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Support\StandardAccounts;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Reports\Data\PortfolioFilter;
use App\Support\Money\Money;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The portfolio dashboard's single source of computed truth.
 *
 * Every figure the Loan Portfolio screen shows comes from one call to
 * {@see analyse()}. There is no per-panel endpoint and no arithmetic in the
 * client: filters can never be honoured by one section and missed by another,
 * and a monetary total is never re-derived from rounded parts.
 *
 * ── Period semantics ──────────────────────────────────────────────────────
 * The date filter fixes two reference points, and every metric uses exactly
 * one of them:
 *
 *  • FLOW metrics — disbursed, collected, expected, recoveries, new/returning
 *    borrowers, the disbursement-vs-collection series — are summed over
 *    [`period->from`, `period->to`].
 *  • STOCK metrics — outstanding principal/interest/fees, receivable, PAR,
 *    ageing, active loan/borrower counts, and the outstanding columns of every
 *    breakdown table — are measured as at `period->asOf()` (the end of the
 *    window): "the book as it stood on that date".
 *
 * Comparisons are the same metric over the immediately preceding equal-length
 * window, shown only when the caller asked for a bounded range and the prior
 * figure is non-zero.
 *
 * ── Correctness rules ─────────────────────────────────────────────────────
 *  • Money is summed in the database as DECIMAL and wrapped in {@see Money};
 *    ratios (rates, percentages) are the only place floats appear, and only
 *    for display.
 *  • "Overdue" is never inferred from a loan's outstanding balance. It is the
 *    unpaid portion of schedule instalments whose due date has passed
 *    (`principal_due + interest_due + fee_due − …_paid`), per the engineering
 *    brief.
 *  • PAR at N days is the OUTSTANDING PRINCIPAL of loans whose earliest unpaid
 *    overdue instalment is ≥ N days past due — not the original loan amount,
 *    and not the whole book.
 *  • Only `RepaymentStatus::Approved` repayments are collections; a reversed
 *    repayment carries `Reversed` and is excluded automatically.
 *  • Tenant isolation is branch access scope: every base query is passed
 *    through {@see applyBranchVisibility()} exactly as
 *    {@see BelongsToBranch::scopeVisibleTo()}
 *    would, so a branch-scoped officer never sees another branch's book.
 *
 * ── Not yet computable (see analyse()['meta']['unavailable']) ─────────────
 *  • Default rate — the loan lifecycle has no "defaulted" state; only
 *    written-off is terminal.
 *  • Multi-currency splits — loans and repayments are single-currency
 *    (`config('naipay.currency')`); the response is tagged with it so a later
 *    multi-currency model slots in without a dashboard rewrite.
 */
final class LoanPortfolioAnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function analyse(PortfolioFilter $filter, Staff $actor): array
    {
        $currency = (string) config('naipay.currency', 'NGN');
        $asOf = $filter->period->asOf();

        $outstanding = $this->outstandingTotals($filter, $actor);
        $overdue = $this->overdueAmount($filter, $actor, $asOf);
        $disbursed = $this->disbursedInWindow($filter, $actor, $filter->period->from, $filter->period->to);
        $collected = $this->collectedInWindow($filter, $actor, $filter->period->from, $filter->period->to);
        $activeLoans = $this->activeLoanCount($filter, $actor, $asOf);
        $activeBorrowers = $this->activeBorrowerCount($filter, $actor, $asOf);
        $parBands = $this->portfolioAtRisk($filter, $actor, $asOf, $outstanding['principal']);
        $par30 = $this->par30Value($parBands);

        $comparison = $this->comparisons($filter, $actor, [
            'total_disbursed' => $disbursed,
            'total_collected' => $collected,
            'outstanding_principal' => $outstanding['principal'],
            'current_receivable' => $outstanding['receivable'],
            'overdue_amount' => $overdue,
            'active_loans' => (string) $activeLoans,
            'active_borrowers' => (string) $activeBorrowers,
            'par30_amount' => $par30,
        ]);

        return [
            'meta' => [
                'currency' => $currency,
                'generated_at' => Carbon::now()->toIso8601String(),
                'as_of' => $asOf->toDateString(),
                'period_semantics' => [
                    'flow_window' => [
                        'from' => $filter->period->from->toDateString(),
                        'to' => $filter->period->to->toDateString(),
                    ],
                    'stock_as_of' => $asOf->toDateString(),
                    'comparison' => $filter->period->comparisonMeaningful ? [
                        'from' => $filter->period->previousFrom->toDateString(),
                        'to' => $filter->period->previousTo->toDateString(),
                    ] : null,
                ],
                'unavailable' => [
                    'default_rate' => 'The loan lifecycle has no defaulted state; only written-off is terminal.',
                    'currency_breakdown' => 'Loans and repayments are single-currency in this release.',
                ],
            ],

            'filters' => $filter->toArray(),

            'kpis' => [
                'total_disbursed' => $this->kpi($disbursed, $comparison['total_disbursed'], 'money'),
                'outstanding_principal' => $this->kpi($outstanding['principal'], $comparison['outstanding_principal'], 'money'),
                'current_receivable' => $this->kpi($outstanding['receivable'], $comparison['current_receivable'], 'money'),
                'total_collected' => $this->kpi($collected, $comparison['total_collected'], 'money'),
                'overdue_amount' => $this->kpi($overdue, $comparison['overdue_amount'], 'money', invertDirection: true),
                'active_loans' => $this->kpi((string) $activeLoans, $comparison['active_loans'], 'count'),
                'active_borrowers' => $this->kpi((string) $activeBorrowers, $comparison['active_borrowers'], 'count'),
                'portfolio_at_risk_30' => $this->kpi($par30, $comparison['par30_amount'], 'money', invertDirection: true),
            ],

            'receivable' => [
                'outstanding_principal' => $outstanding['principal'],
                'outstanding_interest' => $outstanding['interest'],
                'outstanding_fees' => $outstanding['fees'],
                'current_receivable' => $outstanding['receivable'],
                'contracted_receivable' => $outstanding['contracted'],
            ],

            'risk' => $parBands,
            'aging' => $this->aging($filter, $actor, $asOf, $outstanding['principal']),
            'collection_performance' => $this->collectionPerformance($filter, $actor, $asOf, $collected, $overdue),
            'disbursement_vs_collection' => $this->disbursementVsCollection($filter, $actor),

            'by_status' => $this->byStatus($filter, $actor, $outstanding['principal']),
            'by_product' => $this->byBreakdown($filter, $actor, 'product'),
            'by_loan_officer' => $this->byBreakdown($filter, $actor, 'officer'),
            'by_branch' => $this->byBreakdown($filter, $actor, 'branch'),

            'borrowers' => $this->borrowerMetrics($filter, $actor, $asOf),
            'loan_performance' => $this->loanPerformance($filter, $actor),
            'interest_and_fees' => $this->interestAndFees($filter, $actor),
            'write_off_and_recovery' => $this->writeOffAndRecovery($filter, $actor, $asOf),
        ];
    }

    // ── Base query & filters ────────────────────────────────────────────────

    /**
     * A `loans` query carrying every filter that is a plain constraint:
     * branch visibility, branch/product/status/officer/borrower/loan/channel,
     * and the derived days-past-due / repayment-status conditions expressed as
     * schedule sub-queries. Never date-filtered — each metric applies its own
     * window.
     */
    private function loans(PortfolioFilter $filter, Staff $actor): QueryBuilder
    {
        $query = DB::table('loans')->whereNull('loans.deleted_at');

        $this->applyBranchVisibility($query, 'loans.branch_id', $actor);

        if ($filter->branchId !== null) {
            $query->where('loans.branch_id', $filter->branchId);
        }

        if ($filter->loanProductId !== null) {
            $query->where('loans.loan_product_id', $filter->loanProductId);
        }

        if ($filter->status !== null) {
            $query->where('loans.status', $filter->status->value);
        }

        if ($filter->borrowerId !== null) {
            $query->where('loans.merchant_id', $filter->borrowerId);
        }

        if ($filter->loanId !== null) {
            $query->where('loans.id', $filter->loanId);
        }

        if ($filter->disbursementBankAccountId !== null) {
            $query->where('loans.disbursement_bank_account_id', $filter->disbursementBankAccountId);
        }

        if ($filter->officerId !== null) {
            $query->whereIn(
                'loans.merchant_id',
                DB::table('merchants')->where('assigned_officer_id', $filter->officerId)->select('id'),
            );
        }

        if ($filter->repaymentStatus === 'overdue' || $filter->minDaysPastDue !== null) {
            $query->whereExists(fn (QueryBuilder $q) => $this->overdueEntryExists($q, $filter->period->asOf(), $filter->minDaysPastDue));
        } elseif ($filter->repaymentStatus === 'current') {
            $query->whereNotExists(fn (QueryBuilder $q) => $this->overdueEntryExists($q, $filter->period->asOf(), null));
        }

        return $query;
    }

    /**
     * Correlated "this loan has an unpaid instalment that fell due before
     * `$asOf`" — optionally at least `$minDays` ago.
     */
    private function overdueEntryExists(QueryBuilder $q, Carbon $asOf, ?int $minDays): void
    {
        $q->from('loan_schedule_entries as e')
            ->whereColumn('e.loan_id', 'loans.id')
            ->whereDate('e.due_date', '<', $asOf->toDateString())
            ->whereRaw('(e.principal_paid + e.interest_paid + e.fee_paid) < (e.principal_due + e.interest_due + e.fee_due)');

        if ($minDays !== null) {
            $q->whereRaw('DATEDIFF(?, e.due_date) >= ?', [$asOf->toDateString(), $minDays]);
        }
    }

    /**
     * Mirrors BelongsToBranch::scopeVisibleTo — global and department scopes
     * see the whole book; a branch scope sees its own branch, and an
     * unassigned branch scope sees nothing (fail closed).
     */
    private function applyBranchVisibility(QueryBuilder $query, string $column, Staff $actor): void
    {
        $scope = $actor->accessScope();

        if ($scope === AccessScope::Global || $scope === AccessScope::Department) {
            return;
        }

        if ($actor->branch_id === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where($column, $actor->branch_id);
    }

    // ── Stock: outstanding & receivable ─────────────────────────────────────

    /**
     * @return array{principal: string, interest: string, fees: string, receivable: string, contracted: string}
     */
    private function outstandingTotals(PortfolioFilter $filter, Staff $actor): array
    {
        $row = $this->loans($filter, $actor)
            ->where('loans.status', LoanStatus::Disbursed->value)
            ->selectRaw('
                COALESCE(SUM(loans.outstanding_principal), 0) as principal,
                COALESCE(SUM(loans.outstanding_interest), 0)  as interest,
                COALESCE(SUM(loans.outstanding_fees), 0)      as fees,
                COALESCE(SUM(loans.total_payable), 0)         as contracted
            ')
            ->first();

        $principal = Money::fromDecimal((string) ($row->principal ?? '0'));
        $interest = Money::fromDecimal((string) ($row->interest ?? '0'));
        $fees = Money::fromDecimal((string) ($row->fees ?? '0'));

        return [
            'principal' => $principal->toDecimalString(),
            'interest' => $interest->toDecimalString(),
            'fees' => $fees->toDecimalString(),
            'receivable' => $principal->plus($interest)->plus($fees)->toDecimalString(),
            'contracted' => Money::fromDecimal((string) ($row->contracted ?? '0'))->toDecimalString(),
        ];
    }

    /**
     * The unpaid portion of every instalment already due as at `$asOf`, across
     * disbursed loans in scope. Schedule-driven, never balance-driven.
     */
    private function overdueAmount(PortfolioFilter $filter, Staff $actor, Carbon $asOf): string
    {
        $loanIds = $this->loans($filter, $actor)->where('loans.status', LoanStatus::Disbursed->value)->select('loans.id');

        $value = DB::table('loan_schedule_entries as e')
            ->whereIn('e.loan_id', $loanIds)
            ->whereDate('e.due_date', '<', $asOf->toDateString())
            ->whereRaw('(e.principal_paid + e.interest_paid + e.fee_paid) < (e.principal_due + e.interest_due + e.fee_due)')
            ->selectRaw('COALESCE(SUM(
                (e.principal_due + e.interest_due + e.fee_due) - (e.principal_paid + e.interest_paid + e.fee_paid)
            ), 0) as overdue')
            ->value('overdue');

        return Money::fromDecimal((string) ($value ?? '0'))->toDecimalString();
    }

    // ── Flow: disbursed & collected ────────────────────────────────────────

    private function disbursedInWindow(PortfolioFilter $filter, Staff $actor, Carbon $from, Carbon $to): string
    {
        $value = $this->loans($filter, $actor)
            ->whereIn('loans.status', [LoanStatus::Disbursed->value, LoanStatus::WrittenOff->value])
            ->whereBetween('loans.disbursement_date', [$from->toDateString(), $to->toDateString()])
            ->sum('loans.principal_amount');

        return Money::fromDecimal((string) ($value ?: '0'))->toDecimalString();
    }

    private function collectedInWindow(PortfolioFilter $filter, Staff $actor, Carbon $from, Carbon $to): string
    {
        $value = $this->approvedRepayments($filter, $actor, $from, $to)->sum('r.amount');

        return Money::fromDecimal((string) ($value ?: '0'))->toDecimalString();
    }

    /**
     * Approved repayments in the window whose loan is in scope.
     */
    private function approvedRepayments(PortfolioFilter $filter, Staff $actor, Carbon $from, Carbon $to): QueryBuilder
    {
        return DB::table('repayments as r')
            ->whereNull('r.deleted_at')
            ->where('r.status', 'approved')
            ->whereBetween('r.payment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('r.loan_id', $this->loans($filter, $actor)->select('loans.id'));
    }

    private function activeLoanCount(PortfolioFilter $filter, Staff $actor, Carbon $asOf): int
    {
        return (int) $this->loans($filter, $actor)
            ->where('loans.status', LoanStatus::Disbursed->value)
            ->where('loans.outstanding_principal', '>', 0)
            ->whereDate('loans.disbursement_date', '<=', $asOf->toDateString())
            ->count();
    }

    private function activeBorrowerCount(PortfolioFilter $filter, Staff $actor, Carbon $asOf): int
    {
        return (int) $this->loans($filter, $actor)
            ->where('loans.status', LoanStatus::Disbursed->value)
            ->where('loans.outstanding_principal', '>', 0)
            ->whereDate('loans.disbursement_date', '<=', $asOf->toDateString())
            ->distinct()
            ->count('loans.merchant_id');
    }

    // ── Portfolio at risk ─────────────────────────────────────────────────

    /**
     * PAR at each configured threshold. For every disbursed loan in scope with
     * an unpaid overdue instalment as at `$asOf`, the loan's outstanding
     * principal is counted into every band whose day-threshold it meets.
     *
     * @return list<array{threshold_days: int, at_risk_amount: string, percentage_of_outstanding: float|null, loan_count: int}>
     */
    private function portfolioAtRisk(PortfolioFilter $filter, Staff $actor, Carbon $asOf, string $totalOutstandingPrincipal): array
    {
        $thresholds = $this->parThresholds();
        $rows = $this->overdueLoanRows($filter, $actor, $asOf);
        $totalOutstanding = Money::fromDecimal($totalOutstandingPrincipal);

        $bands = [];

        foreach ($thresholds as $days) {
            $amount = Money::zero();
            $count = 0;

            foreach ($rows as $row) {
                if ($row['days_past_due'] >= $days) {
                    $amount = $amount->plus(Money::fromDecimal($row['outstanding_principal']));
                    $count++;
                }
            }

            $bands[] = [
                'threshold_days' => $days,
                'at_risk_amount' => $amount->toDecimalString(),
                'percentage_of_outstanding' => $this->ratio($amount, $totalOutstanding),
                'loan_count' => $count,
            ];
        }

        return $bands;
    }

    private function par30Value(array $bands): string
    {
        foreach ($bands as $band) {
            if ($band['threshold_days'] === 30) {
                return $band['at_risk_amount'];
            }
        }

        return $bands !== [] ? end($bands)['at_risk_amount'] : '0.00';
    }

    /**
     * One row per disbursed in-scope loan that has an unpaid overdue
     * instalment: its outstanding principal and how many days its earliest
     * such instalment is past due as at `$asOf`.
     *
     * @return list<array{loan_id: int, outstanding_principal: string, days_past_due: int}>
     */
    private function overdueLoanRows(PortfolioFilter $filter, Staff $actor, Carbon $asOf): array
    {
        $loanIds = $this->loans($filter, $actor)->where('loans.status', LoanStatus::Disbursed->value)->select('loans.id');

        return DB::table('loan_schedule_entries as e')
            ->join('loans as l', 'l.id', '=', 'e.loan_id')
            ->whereIn('e.loan_id', $loanIds)
            ->whereDate('e.due_date', '<', $asOf->toDateString())
            ->whereRaw('(e.principal_paid + e.interest_paid + e.fee_paid) < (e.principal_due + e.interest_due + e.fee_due)')
            ->groupBy('e.loan_id', 'l.outstanding_principal')
            ->selectRaw('e.loan_id, l.outstanding_principal, MIN(e.due_date) as earliest_due')
            ->get()
            ->map(fn (object $r): array => [
                'loan_id' => (int) $r->loan_id,
                'outstanding_principal' => (string) $r->outstanding_principal,
                'days_past_due' => (int) abs(Carbon::parse($r->earliest_due)->startOfDay()->diffInDays($asOf->copy()->startOfDay())),
            ])
            ->all();
    }

    // ── Ageing ────────────────────────────────────────────────────────────

    /**
     * @return array{buckets: list<array<string, mixed>>, total_loans: int}
     */
    private function aging(PortfolioFilter $filter, Staff $actor, Carbon $asOf, string $totalOutstandingPrincipal): array
    {
        /** @var list<array{label: string, from: int, to: int|null}> $config */
        $config = config('naipay.loans.ageing_buckets', []);
        $totalOutstanding = Money::fromDecimal($totalOutstandingPrincipal);

        $overdueRows = collect($this->overdueLoanRows($filter, $actor, $asOf))->keyBy('loan_id');

        // Every disbursed in-scope loan, with its receivable, so "Current"
        // (never overdue) is a real row rather than a residual.
        $loans = $this->loans($filter, $actor)
            ->where('loans.status', LoanStatus::Disbursed->value)
            ->selectRaw('loans.id, loans.outstanding_principal,
                (loans.outstanding_principal + loans.outstanding_interest + loans.outstanding_fees) as receivable')
            ->get();

        $buckets = [];
        foreach ($config as $bucket) {
            $buckets[$bucket['label']] = [
                'label' => $bucket['label'],
                'from_days' => $bucket['from'],
                'to_days' => $bucket['to'],
                'loan_count' => 0,
                'outstanding_principal' => Money::zero(),
                'outstanding_receivable' => Money::zero(),
            ];
        }

        foreach ($loans as $loan) {
            $daysPastDue = (int) ($overdueRows[$loan->id]['days_past_due'] ?? 0);

            foreach ($config as $bucket) {
                $inBucket = $daysPastDue >= $bucket['from'] && ($bucket['to'] === null || $daysPastDue <= $bucket['to']);
                if (! $inBucket) {
                    continue;
                }

                $buckets[$bucket['label']]['loan_count']++;
                $buckets[$bucket['label']]['outstanding_principal'] = $buckets[$bucket['label']]['outstanding_principal']
                    ->plus(Money::fromDecimal((string) $loan->outstanding_principal));
                $buckets[$bucket['label']]['outstanding_receivable'] = $buckets[$bucket['label']]['outstanding_receivable']
                    ->plus(Money::fromDecimal((string) $loan->receivable));
                break;
            }
        }

        $out = [];
        foreach ($buckets as $bucket) {
            $out[] = [
                'label' => $bucket['label'],
                'from_days' => $bucket['from_days'],
                'to_days' => $bucket['to_days'],
                'loan_count' => $bucket['loan_count'],
                'outstanding_principal' => $bucket['outstanding_principal']->toDecimalString(),
                'outstanding_receivable' => $bucket['outstanding_receivable']->toDecimalString(),
                'percentage_of_portfolio' => $this->ratio($bucket['outstanding_principal'], $totalOutstanding),
            ];
        }

        return ['buckets' => $out, 'total_loans' => $loans->count()];
    }

    // ── Collection performance ────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function collectionPerformance(PortfolioFilter $filter, Staff $actor, Carbon $asOf, string $collected, string $overdue): array
    {
        $loanIds = $this->loans($filter, $actor)
            ->whereIn('loans.status', [LoanStatus::Disbursed->value, LoanStatus::WrittenOff->value])
            ->select('loans.id');

        $expected = DB::table('loan_schedule_entries as e')
            ->whereIn('e.loan_id', $loanIds)
            ->whereBetween('e.due_date', [$filter->period->from->toDateString(), $filter->period->to->toDateString()])
            ->selectRaw('COALESCE(SUM(e.principal_due + e.interest_due + e.fee_due), 0) as expected')
            ->value('expected');

        $expectedMoney = Money::fromDecimal((string) ($expected ?? '0'));
        $collectedMoney = Money::fromDecimal($collected);

        $overdueLoanCount = (int) DB::table('loan_schedule_entries as e')
            ->whereIn('e.loan_id', $this->loans($filter, $actor)->where('loans.status', LoanStatus::Disbursed->value)->select('loans.id'))
            ->whereDate('e.due_date', '<', $asOf->toDateString())
            ->whereRaw('(e.principal_paid + e.interest_paid + e.fee_paid) < (e.principal_due + e.interest_due + e.fee_due)')
            ->distinct()
            ->count('e.loan_id');

        return [
            'expected' => $expectedMoney->toDecimalString(),
            'collected' => $collectedMoney->toDecimalString(),
            'collection_rate' => $this->ratio($collectedMoney, $expectedMoney),
            'overdue_amount' => $overdue,
            'overdue_loan_count' => $overdueLoanCount,
        ];
    }

    // ── Disbursement vs collection series ─────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function disbursementVsCollection(PortfolioFilter $filter, Staff $actor): array
    {
        $granularity = $filter->period->granularity();
        $from = $filter->period->from;
        $to = $filter->period->to;

        [$sqlFormat, $step, $keyFormat, $labelFormat] = match ($granularity) {
            'day' => ['%Y-%m-%d', 'addDay', 'Y-m-d', 'd M'],
            'week' => ['%x-W%v', 'addWeek', 'o-\WW', '\WW, o'],
            default => ['%Y-%m', 'addMonth', 'Y-m', 'M Y'],
        };

        $disbursed = $this->loans($filter, $actor)
            ->whereIn('loans.status', [LoanStatus::Disbursed->value, LoanStatus::WrittenOff->value])
            ->whereBetween('loans.disbursement_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('DATE_FORMAT(loans.disbursement_date, ?) as bucket, COALESCE(SUM(loans.principal_amount), 0) as total', [$sqlFormat])
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $collected = $this->approvedRepayments($filter, $actor, $from, $to)
            ->selectRaw('DATE_FORMAT(r.payment_date, ?) as bucket, COALESCE(SUM(r.amount), 0) as total', [$sqlFormat])
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $points = [];
        $cursor = match ($granularity) {
            'day' => $from->copy()->startOfDay(),
            'week' => $from->copy()->startOfWeek(Carbon::MONDAY),
            default => $from->copy()->startOfMonth(),
        };

        $guard = 0;
        while ($cursor->lessThanOrEqualTo($to) && $guard++ < 400) {
            $key = $cursor->format($keyFormat);
            $points[] = [
                'period' => $key,
                'label' => $cursor->format($labelFormat),
                'disbursed' => Money::fromDecimal((string) ($disbursed[$key] ?? '0'))->toDecimalString(),
                'collected' => Money::fromDecimal((string) ($collected[$key] ?? '0'))->toDecimalString(),
            ];
            $cursor->{$step}();
        }

        return ['granularity' => $granularity, 'points' => $points];
    }

    // ── By status ────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private function byStatus(PortfolioFilter $filter, Staff $actor, string $totalOutstandingPrincipal): array
    {
        $rows = $this->loans($filter, $actor)
            ->groupBy('loans.status')
            ->selectRaw('
                loans.status,
                COUNT(*) as loan_count,
                COALESCE(SUM(loans.outstanding_principal), 0) as principal,
                COALESCE(SUM(loans.outstanding_interest), 0)  as interest,
                COALESCE(SUM(loans.outstanding_fees), 0)      as fees
            ')
            ->get()
            ->keyBy('status');

        $totalOutstanding = Money::fromDecimal($totalOutstandingPrincipal);
        $out = [];

        foreach (LoanStatus::cases() as $status) {
            $row = $rows[$status->value] ?? null;

            if ($row === null && $filter->status !== null && $filter->status !== $status) {
                continue;
            }

            $principal = Money::fromDecimal((string) ($row->principal ?? '0'));
            $interest = Money::fromDecimal((string) ($row->interest ?? '0'));
            $fees = Money::fromDecimal((string) ($row->fees ?? '0'));

            $out[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'loan_count' => (int) ($row->loan_count ?? 0),
                'outstanding_principal' => $principal->toDecimalString(),
                'outstanding_interest' => $interest->toDecimalString(),
                'outstanding_fees' => $fees->toDecimalString(),
                'total_receivable' => $principal->plus($interest)->plus($fees)->toDecimalString(),
                'percentage_of_portfolio' => $this->ratio($principal, $totalOutstanding),
            ];
        }

        return $out;
    }

    // ── By product / officer / branch ────────────────────────────────────

    /**
     * One shape, three groupings. Every column respects the active filters and
     * the period semantics: disbursed/collected are the window, outstanding is
     * as at the window end.
     *
     * @param  'product'|'officer'|'branch'  $dimension
     * @return list<array<string, mixed>>
     */
    private function byBreakdown(PortfolioFilter $filter, Staff $actor, string $dimension): array
    {
        /**
         * $idExpr     — the grouping key, an expression on `loans` or a joined table
         * $nameExpr   — a display label for the row
         * $groupCols  — every non-aggregated selected column, for ONLY_FULL_GROUP_BY
         * $dimJoin    — joins needed just to expose $idExpr (used by the sub-totals)
         * $nameJoin   — additional joins needed to expose $nameExpr (stock query only)
         */
        [$idExpr, $nameExpr, $groupCols, $dimJoin, $nameJoin] = match ($dimension) {
            'product' => [
                'loans.loan_product_id',
                'p.name',
                ['loans.loan_product_id', 'p.name'],
                fn (QueryBuilder $q) => $q,
                fn (QueryBuilder $q) => $q->leftJoin('loan_products as p', 'p.id', '=', 'loans.loan_product_id'),
            ],
            'officer' => [
                'm.assigned_officer_id',
                "TRIM(CONCAT(COALESCE(s.first_name, ''), ' ', COALESCE(s.last_name, '')))",
                ['m.assigned_officer_id', 's.first_name', 's.last_name'],
                fn (QueryBuilder $q) => $q->join('merchants as m', 'm.id', '=', 'loans.merchant_id'),
                fn (QueryBuilder $q) => $q->leftJoin('staff as s', 's.id', '=', 'm.assigned_officer_id'),
            ],
            'branch' => [
                'loans.branch_id',
                'b.name',
                ['loans.branch_id', 'b.name'],
                fn (QueryBuilder $q) => $q,
                fn (QueryBuilder $q) => $q->leftJoin('branches as b', 'b.id', '=', 'loans.branch_id'),
            ],
        };

        $from = $filter->period->from->toDateString();
        $to = $filter->period->to->toDateString();

        // Stock + disbursed-in-window, grouped.
        $base = $this->loans($filter, $actor);
        $dimJoin($base);
        $nameJoin($base);
        $stock = $base
            ->groupBy($groupCols)
            ->selectRaw("
                {$idExpr} as dim_id,
                {$nameExpr} as dim_name,
                COUNT(*) as loan_count,
                COUNT(DISTINCT loans.merchant_id) as borrower_count,
                COALESCE(SUM(CASE WHEN loans.status IN ('disbursed','written_off')
                    AND loans.disbursement_date BETWEEN ? AND ? THEN loans.principal_amount ELSE 0 END), 0) as disbursed,
                COALESCE(SUM(CASE WHEN loans.status = 'disbursed' THEN loans.outstanding_principal ELSE 0 END), 0) as outstanding_principal,
                COALESCE(SUM(CASE WHEN loans.status = 'disbursed'
                    THEN loans.outstanding_principal + loans.outstanding_interest + loans.outstanding_fees ELSE 0 END), 0) as outstanding_receivable
            ", [$from, $to])
            ->get()
            ->keyBy('dim_id');

        // Collected-in-window, grouped by the same dimension.
        $collectedQuery = DB::table('repayments as r')
            ->whereNull('r.deleted_at')
            ->where('r.status', 'approved')
            ->whereBetween('r.payment_date', [$from, $to])
            ->whereIn('r.loan_id', $this->loans($filter, $actor)->select('loans.id'))
            ->join('loans', 'loans.id', '=', 'r.loan_id');
        $dimJoin($collectedQuery);
        $collected = $collectedQuery
            ->groupBy($idExpr)
            ->selectRaw("{$idExpr} as dim_id, COALESCE(SUM(r.amount), 0) as collected")
            ->pluck('collected', 'dim_id');

        // Expected-in-window, for the collection rate.
        $expectedQuery = DB::table('loan_schedule_entries as e')
            ->whereBetween('e.due_date', [$from, $to])
            ->whereIn('e.loan_id', $this->loans($filter, $actor)
                ->whereIn('loans.status', [LoanStatus::Disbursed->value, LoanStatus::WrittenOff->value])->select('loans.id'))
            ->join('loans', 'loans.id', '=', 'e.loan_id');
        $dimJoin($expectedQuery);
        $expected = $expectedQuery
            ->groupBy($idExpr)
            ->selectRaw("{$idExpr} as dim_id, COALESCE(SUM(e.principal_due + e.interest_due + e.fee_due), 0) as expected")
            ->pluck('expected', 'dim_id');

        // Overdue + PAR30, from the per-loan overdue rows attributed to a dimension.
        $overdueByDim = $this->overdueRowsByDimension($filter, $actor, $dimension);

        $out = [];
        foreach ($stock as $dimId => $row) {
            $key = $dimId === null ? 'none' : (string) $dimId;
            $collectedMoney = Money::fromDecimal((string) ($collected[$dimId] ?? '0'));
            $expectedMoney = Money::fromDecimal((string) ($expected[$dimId] ?? '0'));
            $overdue = $overdueByDim[$key] ?? ['overdue' => Money::zero(), 'par30' => Money::zero()];

            $out[] = [
                'id' => $dimId !== null ? (int) $dimId : null,
                'name' => $row->dim_name !== null && $row->dim_name !== ''
                    ? (string) $row->dim_name
                    : ($dimension === 'branch' ? 'Unassigned branch' : ($dimension === 'officer' ? 'Unassigned officer' : 'Unknown')),
                'loans' => (int) $row->loan_count,
                'borrowers' => (int) $row->borrower_count,
                'total_disbursed' => Money::fromDecimal((string) $row->disbursed)->toDecimalString(),
                'outstanding_principal' => Money::fromDecimal((string) $row->outstanding_principal)->toDecimalString(),
                'outstanding_receivable' => Money::fromDecimal((string) $row->outstanding_receivable)->toDecimalString(),
                'collected' => $collectedMoney->toDecimalString(),
                'overdue' => $overdue['overdue']->toDecimalString(),
                'par30' => $overdue['par30']->toDecimalString(),
                'collection_rate' => $this->ratio($collectedMoney, $expectedMoney),
            ];
        }

        usort($out, fn (array $a, array $b): int => (float) $b['outstanding_principal'] <=> (float) $a['outstanding_principal']);

        return $out;
    }

    /**
     * Per-dimension overdue amount and PAR-30 principal, keyed by the string
     * form of the dimension id ('none' for null).
     *
     * @param  'product'|'officer'|'branch'  $dimension
     * @return array<string, array{overdue: Money, par30: Money}>
     */
    private function overdueRowsByDimension(PortfolioFilter $filter, Staff $actor, string $dimension): array
    {
        $parDays = $this->parThresholds();
        $par30 = in_array(30, $parDays, true) ? 30 : (int) config('naipay.loans.par_threshold_days', 30);
        $asOf = $filter->period->asOf();

        [$dimColumn, $joins] = match ($dimension) {
            'product' => ['l.loan_product_id', fn (QueryBuilder $q) => $q],
            'officer' => ['m.assigned_officer_id', fn (QueryBuilder $q) => $q->join('merchants as m', 'm.id', '=', 'l.merchant_id')],
            'branch' => ['l.branch_id', fn (QueryBuilder $q) => $q],
        };

        $loanIds = $this->loans($filter, $actor)->where('loans.status', LoanStatus::Disbursed->value)->select('loans.id');

        $query = DB::table('loan_schedule_entries as e')
            ->join('loans as l', 'l.id', '=', 'e.loan_id')
            ->whereIn('e.loan_id', $loanIds)
            ->whereDate('e.due_date', '<', $asOf->toDateString())
            ->whereRaw('(e.principal_paid + e.interest_paid + e.fee_paid) < (e.principal_due + e.interest_due + e.fee_due)');
        $joins($query);

        $rows = $query
            ->groupBy('e.loan_id', 'l.outstanding_principal', $dimColumn)
            ->selectRaw("e.loan_id, l.outstanding_principal, {$dimColumn} as dim_id,
                MIN(e.due_date) as earliest_due,
                COALESCE(SUM((e.principal_due + e.interest_due + e.fee_due) - (e.principal_paid + e.interest_paid + e.fee_paid)), 0) as overdue_amount")
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $key = $row->dim_id === null ? 'none' : (string) $row->dim_id;
            $out[$key] ??= ['overdue' => Money::zero(), 'par30' => Money::zero()];
            $out[$key]['overdue'] = $out[$key]['overdue']->plus(Money::fromDecimal((string) $row->overdue_amount));

            $daysPastDue = (int) abs(Carbon::parse($row->earliest_due)->startOfDay()->diffInDays($asOf->copy()->startOfDay()));
            if ($daysPastDue >= $par30) {
                $out[$key]['par30'] = $out[$key]['par30']->plus(Money::fromDecimal((string) $row->outstanding_principal));
            }
        }

        return $out;
    }

    // ── Borrowers ────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function borrowerMetrics(PortfolioFilter $filter, Staff $actor, Carbon $asOf): array
    {
        $from = $filter->period->from->toDateString();
        $to = $filter->period->to->toDateString();

        $totalBorrowers = (int) $this->loans($filter, $actor)->distinct()->count('loans.merchant_id');

        $activeBorrowers = $this->activeBorrowerCount($filter, $actor, $asOf);

        // First-ever disbursement per borrower, within scope.
        $firstDisbursement = $this->loans($filter, $actor)
            ->whereIn('loans.status', [LoanStatus::Disbursed->value, LoanStatus::WrittenOff->value])
            ->groupBy('loans.merchant_id')
            ->selectRaw('loans.merchant_id, MIN(loans.disbursement_date) as first_date')
            ->get();

        $newBorrowers = $firstDisbursement->filter(
            fn (object $r) => $r->first_date !== null && $r->first_date >= $from && $r->first_date <= $to
        )->count();

        // Returning: disbursed in the window, but not their first time.
        $disbursedInWindowBorrowers = $this->loans($filter, $actor)
            ->whereIn('loans.status', [LoanStatus::Disbursed->value, LoanStatus::WrittenOff->value])
            ->whereBetween('loans.disbursement_date', [$from, $to])
            ->distinct()->pluck('loans.merchant_id');

        $firstDateByBorrower = $firstDisbursement->keyBy('merchant_id');
        $returningBorrowers = $disbursedInWindowBorrowers->filter(function ($merchantId) use ($firstDateByBorrower, $from): bool {
            $first = $firstDateByBorrower[$merchantId]->first_date ?? null;

            return $first !== null && $first < $from;
        })->count();

        $withOverdue = (int) DB::table('loan_schedule_entries as e')
            ->join('loans as l', 'l.id', '=', 'e.loan_id')
            ->whereIn('e.loan_id', $this->loans($filter, $actor)->where('loans.status', LoanStatus::Disbursed->value)->select('loans.id'))
            ->whereDate('e.due_date', '<', $asOf->toDateString())
            ->whereRaw('(e.principal_paid + e.interest_paid + e.fee_paid) < (e.principal_due + e.interest_due + e.fee_due)')
            ->distinct()->count('l.merchant_id');

        $multipleActive = DB::query()->fromSub(
            $this->loans($filter, $actor)
                ->where('loans.status', LoanStatus::Disbursed->value)
                ->where('loans.outstanding_principal', '>', 0)
                ->groupBy('loans.merchant_id')
                ->havingRaw('COUNT(*) >= 2')
                ->selectRaw('loans.merchant_id'),
            'multi'
        )->count();

        $withMultipleLifetime = DB::query()->fromSub(
            $this->loans($filter, $actor)
                ->whereIn('loans.status', [LoanStatus::Disbursed->value, LoanStatus::WrittenOff->value])
                ->groupBy('loans.merchant_id')
                ->havingRaw('COUNT(*) >= 2')
                ->selectRaw('loans.merchant_id'),
            'multi'
        )->count();

        $outstandingPrincipal = Money::fromDecimal($this->outstandingTotals($filter, $actor)['principal']);
        $disbursedLifetime = $this->loans($filter, $actor)
            ->whereIn('loans.status', [LoanStatus::Disbursed->value, LoanStatus::WrittenOff->value])
            ->selectRaw('COALESCE(SUM(loans.principal_amount), 0) as total, COUNT(*) as cnt')
            ->first();

        $disbursedLoanCount = (int) ($disbursedLifetime->cnt ?? 0);
        $avgLoanSize = $disbursedLoanCount > 0
            ? Money::fromDecimal((string) $disbursedLifetime->total)->divideBy($disbursedLoanCount)->toDecimalString()
            : '0.00';

        return [
            'total_borrowers' => $totalBorrowers,
            'active_borrowers' => $activeBorrowers,
            'new_borrowers' => $newBorrowers,
            'returning_borrowers' => $returningBorrowers,
            'borrowers_with_overdue' => $withOverdue,
            'borrowers_with_multiple_active_loans' => (int) $multipleActive,
            'repeat_borrower_rate' => $totalBorrowers > 0
                ? round($withMultipleLifetime / $totalBorrowers * 100, 2)
                : null,
            'average_outstanding_per_borrower' => $activeBorrowers > 0
                ? $outstandingPrincipal->divideBy($activeBorrowers)->toDecimalString()
                : '0.00',
            'average_loan_size' => $avgLoanSize,
        ];
    }

    // ── Loan performance ─────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function loanPerformance(PortfolioFilter $filter, Staff $actor): array
    {
        $row = $this->loans($filter, $actor)
            ->whereIn('loans.status', [LoanStatus::Disbursed->value, LoanStatus::WrittenOff->value])
            ->selectRaw("
                COUNT(*) as disbursed_ever,
                ROUND(AVG(loans.principal_amount), 2) as avg_amount,
                ROUND(AVG(CASE WHEN loans.status = 'disbursed' THEN loans.outstanding_principal END), 2) as avg_outstanding,
                AVG(DATEDIFF(loans.maturity_date, loans.disbursement_date)) as avg_tenure_days,
                SUM(CASE WHEN loans.status = 'disbursed' AND loans.outstanding_principal > 0 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN loans.status = 'disbursed' AND loans.outstanding_principal = 0
                    AND loans.outstanding_interest = 0 AND loans.outstanding_fees = 0 THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN loans.status = 'written_off' THEN 1 ELSE 0 END) as written_off
            ")
            ->first();

        $disbursedEver = (int) ($row->disbursed_ever ?? 0);

        return [
            'average_loan_amount' => Money::fromDecimal((string) ($row->avg_amount ?? '0'))->toDecimalString(),
            'average_outstanding_loan' => Money::fromDecimal((string) ($row->avg_outstanding ?? '0'))->toDecimalString(),
            'average_loan_tenure_days' => $row->avg_tenure_days !== null ? (int) round((float) $row->avg_tenure_days) : null,
            'active_loans' => (int) ($row->active ?? 0),
            'completed_loans' => (int) ($row->completed ?? 0),
            'written_off_loans' => (int) ($row->written_off ?? 0),
            'loan_completion_rate' => $disbursedEver > 0
                ? round((int) $row->completed / $disbursedEver * 100, 2)
                : null,
            'write_off_rate' => $disbursedEver > 0
                ? round((int) $row->written_off / $disbursedEver * 100, 2)
                : null,
        ];
    }

    // ── Interest & fees ──────────────────────────────────────────────────

    /**
     * Contracted and outstanding are as-at the window end (stock); collected is
     * the window (flow) — the same split as everywhere else.
     *
     * @return array<string, mixed>
     */
    private function interestAndFees(PortfolioFilter $filter, Staff $actor): array
    {
        $stock = $this->loans($filter, $actor)
            ->whereIn('loans.status', [LoanStatus::Disbursed->value, LoanStatus::WrittenOff->value])
            ->selectRaw('
                COALESCE(SUM(loans.total_interest), 0) as interest_contracted,
                COALESCE(SUM(loans.total_fees), 0)     as fees_contracted,
                COALESCE(SUM(CASE WHEN loans.status = \'disbursed\' THEN loans.outstanding_interest ELSE 0 END), 0) as interest_outstanding,
                COALESCE(SUM(CASE WHEN loans.status = \'disbursed\' THEN loans.outstanding_fees ELSE 0 END), 0)     as fees_outstanding
            ')
            ->first();

        $collected = $this->approvedRepayments($filter, $actor, $filter->period->from, $filter->period->to)
            ->selectRaw('
                COALESCE(SUM(r.allocated_interest), 0) as interest_collected,
                COALESCE(SUM(r.allocated_fee), 0)      as fees_collected
            ')
            ->first();

        return [
            'interest_contracted' => Money::fromDecimal((string) $stock->interest_contracted)->toDecimalString(),
            'interest_collected' => Money::fromDecimal((string) $collected->interest_collected)->toDecimalString(),
            'interest_outstanding' => Money::fromDecimal((string) $stock->interest_outstanding)->toDecimalString(),
            'fees_contracted' => Money::fromDecimal((string) $stock->fees_contracted)->toDecimalString(),
            'fees_collected' => Money::fromDecimal((string) $collected->fees_collected)->toDecimalString(),
            'fees_outstanding' => Money::fromDecimal((string) $stock->fees_outstanding)->toDecimalString(),
        ];
    }

    // ── Write-off & recovery ─────────────────────────────────────────────

    /**
     * Written-off amount comes from the ledger (the debit to Written-off
     * Loans), not from the loan row, whose principal balance is zeroed at
     * write-off. Recoveries are approved repayments in the window against a
     * written-off loan.
     *
     * @return array<string, mixed>
     */
    private function writeOffAndRecovery(PortfolioFilter $filter, Staff $actor, Carbon $asOf): array
    {
        $writtenOffLoanIds = $this->loans($filter, $actor)
            ->where('loans.status', LoanStatus::WrittenOff->value)
            ->whereDate('loans.written_off_at', '<=', $asOf->toDateString())
            ->select('loans.id');

        $writtenOffAmount = DB::table('journal_entries as je')
            ->join('journal_transactions as jt', 'jt.id', '=', 'je.journal_transaction_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'je.ledger_account_id')
            ->where('jt.transaction_type', 'loan_write_off')
            ->where('la.code', StandardAccounts::WRITTEN_OFF_LOANS)
            ->whereIn('je.loan_id', $writtenOffLoanIds)
            ->selectRaw('COALESCE(SUM(je.debit_amount), 0) as amount')
            ->value('amount');

        $writtenOffCount = (int) $this->loans($filter, $actor)
            ->where('loans.status', LoanStatus::WrittenOff->value)
            ->whereDate('loans.written_off_at', '<=', $asOf->toDateString())
            ->count();

        $recovered = DB::table('repayments as r')
            ->whereNull('r.deleted_at')
            ->where('r.status', 'approved')
            ->whereBetween('r.payment_date', [$filter->period->from->toDateString(), $filter->period->to->toDateString()])
            ->whereIn('r.loan_id', $this->loans($filter, $actor)->where('loans.status', LoanStatus::WrittenOff->value)->select('loans.id'))
            ->sum('r.amount');

        $writtenOffMoney = Money::fromDecimal((string) ($writtenOffAmount ?? '0'));
        $recoveredMoney = Money::fromDecimal((string) ($recovered ?: '0'));

        return [
            'written_off_amount' => $writtenOffMoney->toDecimalString(),
            'written_off_loans' => $writtenOffCount,
            'recovered_amount' => $recoveredMoney->toDecimalString(),
            'recovery_rate' => $this->ratio($recoveredMoney, $writtenOffMoney),
        ];
    }

    // ── Comparisons ──────────────────────────────────────────────────────

    /**
     * Re-runs the previous window and diffs each headline figure.
     *
     * @param  array<string, string>  $current
     * @return array<string, array<string, mixed>>
     */
    private function comparisons(PortfolioFilter $filter, Staff $actor, array $current): array
    {
        if (! $filter->period->comparisonMeaningful) {
            return array_map(fn (string $value): array => $this->diff($value, null), $current);
        }

        $prevFrom = $filter->period->previousFrom;
        $prevTo = $filter->period->previousTo;

        $prevOutstanding = $this->outstandingTotalsAsOf($filter, $actor, $prevTo);

        $previous = [
            'total_disbursed' => $this->disbursedInWindow($filter, $actor, $prevFrom, $prevTo),
            'total_collected' => $this->collectedInWindow($filter, $actor, $prevFrom, $prevTo),
            'outstanding_principal' => $prevOutstanding['principal'],
            'current_receivable' => $prevOutstanding['receivable'],
            'overdue_amount' => $this->overdueAmount($filter, $actor, $prevTo),
            'active_loans' => (string) $this->activeLoanCount($filter, $actor, $prevTo),
            'active_borrowers' => (string) $this->activeBorrowerCount($filter, $actor, $prevTo),
            'par30_amount' => $this->par30Value($this->portfolioAtRisk($filter, $actor, $prevTo, $prevOutstanding['principal'])),
        ];

        $out = [];
        foreach ($current as $key => $value) {
            $out[$key] = $this->diff($value, $previous[$key] ?? null);
        }

        return $out;
    }

    /**
     * outstandingTotals is "as at now" via the loan balance cache; for a past
     * date the cache cannot be trusted, so the previous-period outstanding is
     * reconstructed from the ledger's principal-receivable movement. Interest
     * and fees are recognised only on allocation, so the receivable delta uses
     * the same ledger accounts.
     *
     * @return array{principal: string, receivable: string}
     */
    private function outstandingTotalsAsOf(PortfolioFilter $filter, Staff $actor, Carbon $asOf): array
    {
        $loanIds = $this->loans($filter, $actor)->select('loans.id');

        $principal = DB::table('journal_entries as je')
            ->join('journal_transactions as jt', 'jt.id', '=', 'je.journal_transaction_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'je.ledger_account_id')
            ->whereIn('je.loan_id', $loanIds)
            ->where('la.code', StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE)
            ->whereDate('jt.transaction_date', '<=', $asOf->toDateString())
            ->selectRaw('COALESCE(SUM(je.debit_amount - je.credit_amount), 0) as balance')
            ->value('balance');

        $interest = DB::table('journal_entries as je')
            ->join('journal_transactions as jt', 'jt.id', '=', 'je.journal_transaction_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'je.ledger_account_id')
            ->whereIn('je.loan_id', $loanIds)
            ->where('la.code', StandardAccounts::INTEREST_RECEIVABLE)
            ->whereDate('jt.transaction_date', '<=', $asOf->toDateString())
            ->selectRaw('COALESCE(SUM(je.debit_amount - je.credit_amount), 0) as balance')
            ->value('balance');

        $principalMoney = Money::fromDecimal((string) ($principal ?? '0'));
        $interestMoney = Money::fromDecimal((string) ($interest ?? '0'));

        return [
            'principal' => $principalMoney->toDecimalString(),
            'receivable' => $principalMoney->plus($interestMoney)->toDecimalString(),
        ];
    }

    // ── Shaping helpers ──────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $comparison
     * @return array<string, mixed>
     */
    private function kpi(string $value, array $comparison, string $format, bool $invertDirection = false): array
    {
        $direction = $comparison['change_pct'];

        $tone = 'flat';
        if ($direction !== null && $direction !== 0.0) {
            $rising = $direction > 0;
            $tone = ($rising xor $invertDirection) ? 'positive' : 'negative';
        }

        return [
            'value' => $value,
            'format' => $format,
            'previous' => $comparison['previous'],
            'change_pct' => $comparison['change_pct'],
            'change_absolute' => $comparison['change_absolute'],
            'direction' => $comparison['direction'],
            'tone' => $tone,
            'comparison_available' => $comparison['comparison_available'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function diff(string $current, ?string $previous): array
    {
        if ($previous === null) {
            return [
                'previous' => null,
                'change_pct' => null,
                'change_absolute' => null,
                'direction' => null,
                'comparison_available' => false,
            ];
        }

        $currentF = (float) $current;
        $previousF = (float) $previous;
        $delta = $currentF - $previousF;

        $changePct = $previousF !== 0.0 ? round($delta / abs($previousF) * 100, 2) : null;

        return [
            'previous' => $previous,
            'change_pct' => $changePct,
            'change_absolute' => (string) round($delta, 2),
            'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
            'comparison_available' => true,
        ];
    }

    private function ratio(Money $numerator, Money $denominator): ?float
    {
        if (! $denominator->isPositive()) {
            return null;
        }

        return round((float) $numerator->toDecimalString() / (float) $denominator->toDecimalString() * 100, 2);
    }

    /**
     * @return list<int>
     */
    private function parThresholds(): array
    {
        /** @var list<int> $configured */
        $configured = config('naipay.loans.par_thresholds', []);

        if ($configured === []) {
            $configured = [(int) config('naipay.loans.par_threshold_days', 30)];
        }

        $configured = array_values(array_unique(array_map('intval', $configured)));
        sort($configured);

        return $configured;
    }
}
