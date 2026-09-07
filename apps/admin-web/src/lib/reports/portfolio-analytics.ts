'use client';

import { useMutation, useQuery } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type { LoanStatusKey } from '@/lib/reports/types';

/**
 * Contract for GET /admin/reports/loan-portfolio/analytics — the single
 * endpoint behind the Loan Portfolio dashboard. Every monetary figure is a
 * plain decimal string ("125000.50"); render with `formatAmountString` /
 * `formatCompactMoney`, never `Number()` for anything but a chart axis.
 *
 * See App\Domains\Reports\Services\LoanPortfolioAnalyticsService.
 */

export type PortfolioRangeKey =
  | 'today'
  | 'yesterday'
  | 'this_week'
  | 'last_week'
  | 'this_month'
  | 'last_month'
  | 'this_quarter'
  | 'last_quarter'
  | 'this_year'
  | 'last_year'
  | 'all_time'
  | 'custom';

export type RepaymentStatusFilter = 'current' | 'overdue';

/** The filter state the dashboard holds and mirrors into the URL query string. */
export interface PortfolioFilterState {
  range: PortfolioRangeKey;
  date_from?: string;
  date_to?: string;
  loan_product_id?: number;
  status?: LoanStatusKey;
  loan_officer_id?: number;
  branch_id?: number;
  borrower_id?: number;
  loan_id?: number;
  disbursement_channel_id?: number;
  repayment_status?: RepaymentStatusFilter;
  min_days_past_due?: number;
}

export const DEFAULT_PORTFOLIO_FILTERS: PortfolioFilterState = { range: 'this_month' };

export interface KpiFigure {
  value: string;
  format: 'money' | 'count';
  previous: string | null;
  change_pct: number | null;
  change_absolute: string | null;
  direction: 'up' | 'down' | 'flat' | null;
  tone: 'positive' | 'negative' | 'flat';
  comparison_available: boolean;
}

export interface ParBand {
  threshold_days: number;
  at_risk_amount: string;
  percentage_of_outstanding: number | null;
  loan_count: number;
}

export interface AgingBucketRow {
  label: string;
  from_days: number;
  to_days: number | null;
  loan_count: number;
  outstanding_principal: string;
  outstanding_receivable: string;
  percentage_of_portfolio: number | null;
}

export interface FlowPoint {
  period: string;
  label: string;
  disbursed: string;
  collected: string;
}

export interface StatusRow {
  status: LoanStatusKey;
  label: string;
  loan_count: number;
  outstanding_principal: string;
  outstanding_interest: string;
  outstanding_fees: string;
  total_receivable: string;
  percentage_of_portfolio: number | null;
}

export interface BreakdownRow {
  id: number | null;
  name: string;
  loans: number;
  borrowers: number;
  total_disbursed: string;
  outstanding_principal: string;
  outstanding_receivable: string;
  collected: string;
  overdue: string;
  par30: string;
  collection_rate: number | null;
}

export interface PortfolioAnalytics {
  meta: {
    currency: string;
    generated_at: string;
    as_of: string;
    period_semantics: {
      flow_window: { from: string; to: string };
      stock_as_of: string;
      comparison: { from: string; to: string } | null;
    };
    unavailable: Record<string, string>;
  };
  filters: {
    range: PortfolioRangeKey;
    range_label: string;
    date_from: string;
    date_to: string;
    is_default: boolean;
    loan_product_id: number | null;
    status: LoanStatusKey | null;
    loan_officer_id: number | null;
    branch_id: number | null;
    borrower_id: number | null;
    loan_id: number | null;
    disbursement_channel_id: number | null;
    min_days_past_due: number | null;
    repayment_status: RepaymentStatusFilter | null;
    currency: string | null;
  };
  kpis: {
    total_disbursed: KpiFigure;
    outstanding_principal: KpiFigure;
    current_receivable: KpiFigure;
    total_collected: KpiFigure;
    overdue_amount: KpiFigure;
    active_loans: KpiFigure;
    active_borrowers: KpiFigure;
    portfolio_at_risk_30: KpiFigure;
  };
  receivable: {
    outstanding_principal: string;
    outstanding_interest: string;
    outstanding_fees: string;
    current_receivable: string;
    contracted_receivable: string;
  };
  risk: ParBand[];
  aging: { buckets: AgingBucketRow[]; total_loans: number };
  collection_performance: {
    expected: string;
    collected: string;
    collection_rate: number | null;
    overdue_amount: string;
    overdue_loan_count: number;
  };
  disbursement_vs_collection: { granularity: 'day' | 'week' | 'month'; points: FlowPoint[] };
  by_status: StatusRow[];
  by_product: BreakdownRow[];
  by_loan_officer: BreakdownRow[];
  by_branch: BreakdownRow[];
  borrowers: {
    total_borrowers: number;
    active_borrowers: number;
    new_borrowers: number;
    returning_borrowers: number;
    borrowers_with_overdue: number;
    borrowers_with_multiple_active_loans: number;
    repeat_borrower_rate: number | null;
    average_outstanding_per_borrower: string;
    average_loan_size: string;
  };
  loan_performance: {
    average_loan_amount: string;
    average_outstanding_loan: string;
    average_loan_tenure_days: number | null;
    active_loans: number;
    completed_loans: number;
    written_off_loans: number;
    loan_completion_rate: number | null;
    write_off_rate: number | null;
  };
  interest_and_fees: {
    interest_contracted: string;
    interest_collected: string;
    interest_outstanding: string;
    fees_contracted: string;
    fees_collected: string;
    fees_outstanding: string;
  };
  write_off_and_recovery: {
    written_off_amount: string;
    written_off_loans: number;
    recovered_amount: string;
    recovery_rate: number | null;
  };
}

// ── Filter ⇄ query-string ────────────────────────────────────────────────

const NUMERIC_KEYS = [
  'loan_product_id',
  'loan_officer_id',
  'branch_id',
  'borrower_id',
  'loan_id',
  'disbursement_channel_id',
  'min_days_past_due',
] as const;

/** Parse a URLSearchParams into filter state, ignoring anything unrecognised. */
export function filtersFromSearchParams(params: URLSearchParams): PortfolioFilterState {
  const state: PortfolioFilterState = { range: DEFAULT_PORTFOLIO_FILTERS.range };

  const range = params.get('range');
  if (range) state.range = range as PortfolioRangeKey;

  const from = params.get('date_from');
  const to = params.get('date_to');
  if (from) state.date_from = from;
  if (to) state.date_to = to;

  for (const key of NUMERIC_KEYS) {
    const raw = params.get(key);
    if (raw !== null && raw !== '' && Number.isFinite(Number(raw))) {
      state[key] = Number(raw);
    }
  }

  const status = params.get('status');
  if (status) state.status = status as LoanStatusKey;

  const repayment = params.get('repayment_status');
  if (repayment === 'current' || repayment === 'overdue') state.repayment_status = repayment;

  return state;
}

/** Serialise filter state to a query string, omitting defaults and blanks. */
export function filtersToSearchParams(state: PortfolioFilterState): URLSearchParams {
  const params = new URLSearchParams();

  if (state.range && state.range !== DEFAULT_PORTFOLIO_FILTERS.range) params.set('range', state.range);
  if (state.range === 'custom') {
    if (state.date_from) params.set('date_from', state.date_from);
    if (state.date_to) params.set('date_to', state.date_to);
  }
  for (const key of NUMERIC_KEYS) {
    const value = state[key];
    if (value !== undefined && value !== null) params.set(key, String(value));
  }
  if (state.status) params.set('status', state.status);
  if (state.repayment_status) params.set('repayment_status', state.repayment_status);

  return params;
}

/** The request query object sent to the API (same keys, minus empties). */
export function portfolioRequestQuery(state: PortfolioFilterState): Record<string, string> {
  const params = filtersToSearchParams(state);
  // `range` is dropped from the query string when it equals the default, but
  // the API is happy to receive it explicitly and it keeps the query key
  // stable for react-query.
  if (!params.has('range')) params.set('range', state.range);
  return Object.fromEntries(params.entries());
}

export function activeFilterCount(state: PortfolioFilterState): number {
  let count = 0;
  if (state.range !== DEFAULT_PORTFOLIO_FILTERS.range) count++;
  for (const key of NUMERIC_KEYS) if (state[key] !== undefined) count++;
  if (state.status) count++;
  if (state.repayment_status) count++;
  return count;
}

// ── Hook ─────────────────────────────────────────────────────────────────

export function usePortfolioAnalytics(state: PortfolioFilterState) {
  const query = portfolioRequestQuery(state);

  return useQuery({
    queryKey: ['reports', 'loan-portfolio-analytics', query],
    queryFn: ({ signal }) =>
      api.get<PortfolioAnalytics>('/admin/reports/loan-portfolio/analytics', { signal, query }),
    placeholderData: (previous) => previous,
    staleTime: 30_000,
  });
}

export type PortfolioExportSection =
  | 'summary'
  | 'status'
  | 'products'
  | 'officers'
  | 'branches'
  | 'aging'
  | 'risk';

/**
 * Downloads a section of the dashboard as CSV — computed from the same
 * endpoint, for the same filters. The API client's `download()` attaches the
 * bearer token a plain `<a href>` could not.
 */
export function usePortfolioAnalyticsExport(state: PortfolioFilterState) {
  return useMutation({
    mutationFn: async (section: PortfolioExportSection) => {
      const blob = await api.download('/admin/reports/loan-portfolio/analytics/export', {
        query: { ...portfolioRequestQuery(state), section },
      });

      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `loan-portfolio-${section}-${state.date_from ?? 'current'}.csv`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    },
  });
}
