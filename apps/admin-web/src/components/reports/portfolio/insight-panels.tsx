'use client';

import type { ReactNode } from 'react';

import { Card } from '@/components/ui/card';
import { formatAmountString, formatNumber } from '@/lib/format';
import type { PortfolioAnalytics } from '@/lib/reports/portfolio-analytics';

function rate(value: number | null): string {
  return value === null ? '—' : `${value.toFixed(1)}%`;
}

function Metric({ label, value, hint }: { label: string; value: ReactNode; hint?: string }) {
  return (
    <div className="flex items-baseline justify-between gap-4 border-b border-slate-100 py-2 last:border-0">
      <span className="text-sm text-slate-600">
        {label}
        {hint ? <span className="ml-1 text-xs text-slate-400">{hint}</span> : null}
      </span>
      <span className="numeric text-sm font-medium text-slate-900">{value}</span>
    </div>
  );
}

function Panel({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
  return (
    <Card>
      <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
      {subtitle ? <p className="mt-0.5 text-xs text-slate-500">{subtitle}</p> : null}
      <div className="mt-2">{children}</div>
    </Card>
  );
}

export function CollectionPerformancePanel({
  data,
  currency,
}: {
  data: PortfolioAnalytics['collection_performance'];
  currency: string;
}) {
  return (
    <Panel title="Collection performance" subtitle="Against instalments scheduled to fall due in the period.">
      <Metric label="Expected this period" value={formatAmountString(data.expected, currency)} />
      <Metric label="Collected" value={formatAmountString(data.collected, currency)} />
      <Metric label="Collection rate" value={rate(data.collection_rate)} />
      <Metric label="Overdue amount" value={formatAmountString(data.overdue_amount, currency)} />
      <Metric label="Overdue loans" value={formatNumber(data.overdue_loan_count)} />
    </Panel>
  );
}

export function BorrowerInsightsPanel({
  data,
  currency,
}: {
  data: PortfolioAnalytics['borrowers'];
  currency: string;
}) {
  return (
    <Panel title="Borrowers" subtitle="Each borrower counted once, however many loans they hold.">
      <Metric label="Total borrowers" value={formatNumber(data.total_borrowers)} />
      <Metric label="Active borrowers" value={formatNumber(data.active_borrowers)} />
      <Metric label="New borrowers" value={formatNumber(data.new_borrowers)} hint="first loan this period" />
      <Metric label="Returning borrowers" value={formatNumber(data.returning_borrowers)} />
      <Metric label="With an overdue loan" value={formatNumber(data.borrowers_with_overdue)} />
      <Metric label="With multiple active loans" value={formatNumber(data.borrowers_with_multiple_active_loans)} />
      <Metric label="Repeat borrower rate" value={rate(data.repeat_borrower_rate)} />
      <Metric
        label="Avg. outstanding / borrower"
        value={formatAmountString(data.average_outstanding_per_borrower, currency)}
      />
      <Metric label="Average loan size" value={formatAmountString(data.average_loan_size, currency)} />
    </Panel>
  );
}

export function LoanPerformancePanel({
  data,
  currency,
}: {
  data: PortfolioAnalytics['loan_performance'];
  currency: string;
}) {
  return (
    <Panel title="Loan performance">
      <Metric label="Average loan amount" value={formatAmountString(data.average_loan_amount, currency)} />
      <Metric label="Average outstanding loan" value={formatAmountString(data.average_outstanding_loan, currency)} />
      <Metric
        label="Average tenure"
        value={data.average_loan_tenure_days === null ? '—' : `${formatNumber(data.average_loan_tenure_days)} days`}
      />
      <Metric label="Active loans" value={formatNumber(data.active_loans)} />
      <Metric label="Completed loans" value={formatNumber(data.completed_loans)} hint="fully repaid" />
      <Metric label="Loan completion rate" value={rate(data.loan_completion_rate)} />
      <Metric label="Written-off loans" value={formatNumber(data.written_off_loans)} />
      <Metric label="Write-off rate" value={rate(data.write_off_rate)} />
    </Panel>
  );
}

export function InterestFeesPanel({
  data,
  currency,
}: {
  data: PortfolioAnalytics['interest_and_fees'];
  currency: string;
}) {
  return (
    <Panel title="Interest & fees" subtitle="Contracted and outstanding as at the period end; collected within it.">
      <Metric label="Interest contracted" value={formatAmountString(data.interest_contracted, currency)} />
      <Metric label="Interest collected" value={formatAmountString(data.interest_collected, currency)} />
      <Metric label="Interest outstanding" value={formatAmountString(data.interest_outstanding, currency)} />
      <Metric label="Fees contracted" value={formatAmountString(data.fees_contracted, currency)} />
      <Metric label="Fees collected" value={formatAmountString(data.fees_collected, currency)} />
      <Metric label="Fees outstanding" value={formatAmountString(data.fees_outstanding, currency)} />
    </Panel>
  );
}

export function WriteOffRecoveryPanel({
  data,
  currency,
}: {
  data: PortfolioAnalytics['write_off_and_recovery'];
  currency: string;
}) {
  return (
    <Panel title="Write-off & recovery" subtitle="Write-off amount is taken from the ledger.">
      <Metric label="Written-off amount" value={formatAmountString(data.written_off_amount, currency)} />
      <Metric label="Written-off loans" value={formatNumber(data.written_off_loans)} />
      <Metric label="Recovered in period" value={formatAmountString(data.recovered_amount, currency)} />
      <Metric label="Recovery rate" value={rate(data.recovery_rate)} />
    </Panel>
  );
}
