'use client';

import { Info } from 'lucide-react';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { Suspense, useCallback, useMemo, useState } from 'react';

import { BreakdownTable, StatusTable } from '@/components/reports/portfolio/breakdown-tables';
import { DisbursementCollectionChart } from '@/components/reports/portfolio/flow-chart';
import {
  BorrowerInsightsPanel,
  CollectionPerformancePanel,
  InterestFeesPanel,
  LoanPerformancePanel,
  WriteOffRecoveryPanel,
} from '@/components/reports/portfolio/insight-panels';
import { KpiCard, KpiGrid } from '@/components/reports/portfolio/kpi-grid';
import { PortfolioFilters } from '@/components/reports/portfolio/portfolio-filters';
import { AgingPanel, RiskPanel } from '@/components/reports/portfolio/risk-aging';
import { Alert } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { formatAmountString, formatDate } from '@/lib/format';
import {
  filtersFromSearchParams,
  filtersToSearchParams,
  type PortfolioFilterState,
  usePortfolioAnalytics,
} from '@/lib/reports/portfolio-analytics';

export default function LoanPortfolioPage() {
  return (
    <Suspense fallback={<PageHeader title="Loan portfolio" description="Portfolio overview and risk monitoring." />}>
      <LoanPortfolioDashboard />
    </Suspense>
  );
}

function LoanPortfolioDashboard() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const [showAllMetrics, setShowAllMetrics] = useState(false);

  const filters = useMemo(
    () => filtersFromSearchParams(new URLSearchParams(searchParams.toString())),
    [searchParams],
  );

  const applyFilters = useCallback(
    (next: PortfolioFilterState) => {
      const qs = filtersToSearchParams(next).toString();
      router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
    },
    [router, pathname],
  );

  const { data, isLoading, isFetching, error } = usePortfolioAnalytics(filters);

  // Carry the portfolio-wide context (product / branch / borrower) into every
  // drill-down so the loan list opens on the same slice.
  const drillHref = useCallback(
    (extra: Record<string, string | number | undefined>) => {
      const params = new URLSearchParams();
      if (filters.loan_product_id) params.set('loan_product_id', String(filters.loan_product_id));
      if (filters.branch_id) params.set('branch_id', String(filters.branch_id));
      if (filters.borrower_id) params.set('merchant_id', String(filters.borrower_id));
      for (const [key, value] of Object.entries(extra)) {
        if (value !== undefined && value !== '') params.set(key, String(value));
      }
      const qs = params.toString();
      return qs ? `/loans?${qs}` : '/loans';
    },
    [filters.loan_product_id, filters.branch_id, filters.borrower_id],
  );

  const currency = data?.meta.currency ?? 'NGN';
  const isEmpty =
    data !== undefined && data.aging.total_loans === 0 && data.by_status.every((row) => row.loan_count === 0);

  return (
    <>
      <PageHeader
        title="Loan portfolio"
        description="Portfolio overview and risk monitoring."
        actions={
          data ? (
            <span className="text-xs text-slate-500">
              {data.filters.range_label} · as at {formatDate(data.meta.as_of)}
              {isFetching ? ' · updating…' : ''}
            </span>
          ) : null
        }
      />

      <PortfolioFilters value={filters} onApply={applyFilters} isFetching={isFetching && !isLoading} />

      <QueryState isLoading={isLoading} error={error && !data ? error : null}>
        {data ? (
          <div className="space-y-6">
            {isEmpty ? (
              <Alert tone="info">No loans match these filters. Adjust the range or clear the filters to see the current portfolio.</Alert>
            ) : null}

            {/* ── Headline KPIs ─────────────────────────────────────────── */}
            <KpiGrid>
              <KpiCard
                label="Total disbursed"
                figure={data.kpis.total_disbursed}
                currency={currency}
                definition="Principal actually released to borrowers in the period."
                href={drillHref({ status: 'disbursed', disbursement_date_from: data.meta.period_semantics.flow_window.from, disbursement_date_to: data.meta.period_semantics.flow_window.to })}
              />
              <KpiCard
                label="Outstanding principal"
                figure={data.kpis.outstanding_principal}
                currency={currency}
                definition="Principal still unpaid on active loans."
                href={drillHref({ status: 'disbursed' })}
              />
              <KpiCard
                label="Current receivable"
                figure={data.kpis.current_receivable}
                currency={currency}
                definition="Outstanding principal plus outstanding interest and fees."
              />
              <KpiCard
                label="Total collected"
                figure={data.kpis.total_collected}
                currency={currency}
                definition="Approved repayments received in the period."
              />
              <KpiCard
                label="Overdue"
                figure={data.kpis.overdue_amount}
                currency={currency}
                definition="Instalment amounts past their due date and still unpaid."
                href={drillHref({ status: 'disbursed' })}
              />
              <KpiCard
                label="PAR 30"
                figure={data.kpis.portfolio_at_risk_30}
                currency={currency}
                definition="Outstanding principal of loans 30+ days in arrears."
                href={drillHref({ status: 'disbursed' })}
              />
              <KpiCard
                label="Active loans"
                figure={data.kpis.active_loans}
                currency={currency}
                href={drillHref({ status: 'disbursed' })}
              />
              <KpiCard label="Active borrowers" figure={data.kpis.active_borrowers} currency={currency} />
            </KpiGrid>

            {/* ── Receivable definitions ────────────────────────────────── */}
            <Card className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
              {(
                [
                  ['Outstanding principal', data.receivable.outstanding_principal],
                  ['Outstanding interest', data.receivable.outstanding_interest],
                  ['Outstanding fees', data.receivable.outstanding_fees],
                  ['Current receivable', data.receivable.current_receivable],
                  ['Contracted receivable', data.receivable.contracted_receivable],
                ] as const
              ).map(([label, value]) => (
                <div key={label}>
                  <p className="text-xs font-medium text-slate-500">{label}</p>
                  <p className="numeric mt-1 text-base font-semibold text-slate-900">
                    {formatAmountString(value, currency)}
                  </p>
                </div>
              ))}
            </Card>

            {/* ── Disbursement vs collection ────────────────────────────── */}
            <DisbursementCollectionChart
              points={data.disbursement_vs_collection.points}
              granularity={data.disbursement_vs_collection.granularity}
              currency={currency}
            />

            {/* ── Risk & aging ─────────────────────────────────────────── */}
            <RiskPanel
              bands={data.risk}
              currency={currency}
              onDrill={() => router.push(drillHref({ status: 'disbursed' }))}
            />
            <AgingPanel
              buckets={data.aging.buckets}
              totalLoans={data.aging.total_loans}
              currency={currency}
              onDrill={() => router.push(drillHref({ status: 'disbursed' }))}
            />

            {/* ── Collection performance ────────────────────────────────── */}
            <CollectionPerformancePanel data={data.collection_performance} currency={currency} />

            {/* ── Breakdowns ───────────────────────────────────────────── */}
            <StatusTable
              rows={data.by_status}
              currency={currency}
              onDrill={(row) => router.push(drillHref({ status: row.status }))}
            />
            <BreakdownTable
              title="By product"
              subtitle="Click a product to open its loans."
              dimensionHeading="Product"
              rows={data.by_product}
              currency={currency}
              emptyLabel="No loans against any product in this view."
              onDrill={(row) => router.push(drillHref({ loan_product_id: row.id ?? undefined, status: 'disbursed' }))}
            />
            <BreakdownTable
              title="Loan officer performance"
              dimensionHeading="Officer"
              rows={data.by_loan_officer}
              currency={currency}
              emptyLabel="No loans attributed to an officer in this view."
            />
            <BreakdownTable
              title="By branch"
              subtitle="Click a branch to open its loans."
              dimensionHeading="Branch"
              rows={data.by_branch}
              currency={currency}
              emptyLabel="No branch data in this view."
              onDrill={(row) => router.push(drillHref({ branch_id: row.id ?? undefined, status: 'disbursed' }))}
            />

            {/* ── Progressive disclosure: portfolio insights ────────────── */}
            {showAllMetrics ? (
              <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <BorrowerInsightsPanel data={data.borrowers} currency={currency} />
                <LoanPerformancePanel data={data.loan_performance} currency={currency} />
                <div className="space-y-6">
                  <InterestFeesPanel data={data.interest_and_fees} currency={currency} />
                  <WriteOffRecoveryPanel data={data.write_off_and_recovery} currency={currency} />
                </div>
              </div>
            ) : (
              <div className="flex justify-center">
                <Button type="button" variant="secondary" onClick={() => setShowAllMetrics(true)}>
                  Show borrower, performance & interest analytics
                </Button>
              </div>
            )}

            {Object.keys(data.meta.unavailable).length > 0 ? (
              <p className="flex items-start gap-1.5 text-xs text-slate-400">
                <Info className="mt-px size-3.5 shrink-0" aria-hidden />
                <span>
                  Not shown on the current data model:{' '}
                  {Object.values(data.meta.unavailable).join(' ')}
                </span>
              </p>
            ) : null}
          </div>
        ) : null}
      </QueryState>
    </>
  );
}
