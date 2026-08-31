'use client';

import { Card, StatCard } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { useDashboard } from '@/lib/reports/use-reports';
import { formatAmountString, formatNumber, formatPercentage } from '@/lib/format';
import type { LoanApplicationStatusKey, LoanStatusKey } from '@/lib/reports/types';

const loanApplicationLabels: Record<LoanApplicationStatusKey, string> = {
  draft: 'Draft',
  submitted: 'Submitted',
  under_assessment: 'Under assessment',
  recommended: 'Recommended',
  approved: 'Approved',
  rejected: 'Rejected',
  withdrawn: 'Withdrawn',
  expired: 'Expired',
};

const loanStatusLabels: Record<LoanStatusKey, string> = {
  pending_approval: 'Pending approval',
  pending_disbursement: 'Pending disbursement',
  disbursed: 'Disbursed',
  written_off: 'Written off',
};

export default function DashboardPage() {
  const { data, isLoading, error } = useDashboard();

  return (
    <>
      <PageHeader
        title="Dashboard"
        description="Portfolio, applications and collections at a glance."
      />

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <div className="space-y-6">
            <section className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <StatCard label="Total merchants" value={formatNumber(data.merchants.total)} />
              <StatCard
                label="Active merchants"
                value={formatNumber(data.merchants.active)}
                tone="success"
              />
              <StatCard
                label="Pending onboarding"
                value={formatNumber(data.merchants.pending_onboarding)}
                tone={data.merchants.pending_onboarding > 0 ? 'warning' : 'default'}
              />
            </section>

            <section className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              <Card>
                <h2 className="text-sm font-semibold text-slate-900">Loan applications</h2>
                <dl className="mt-4 space-y-2">
                  {(Object.keys(loanApplicationLabels) as LoanApplicationStatusKey[]).map(
                    (key) => (
                      <div key={key} className="flex items-center justify-between text-sm">
                        <dt className="text-slate-600">{loanApplicationLabels[key]}</dt>
                        <dd className="numeric font-medium text-slate-900">
                          {formatNumber(data.loan_applications[key])}
                        </dd>
                      </div>
                    ),
                  )}
                </dl>
              </Card>

              <Card>
                <h2 className="text-sm font-semibold text-slate-900">Loans</h2>
                <dl className="mt-4 space-y-2">
                  {(Object.keys(loanStatusLabels) as LoanStatusKey[]).map((key) => (
                    <div key={key} className="flex items-center justify-between text-sm">
                      <dt className="text-slate-600">{loanStatusLabels[key]}</dt>
                      <dd className="numeric font-medium text-slate-900">
                        {formatNumber(data.loans[key])}
                      </dd>
                    </div>
                  ))}
                </dl>
                <div className="mt-4 space-y-2 border-t border-slate-100 pt-3">
                  <div className="flex items-center justify-between text-sm">
                    <dt className="font-medium text-slate-900">Total outstanding principal</dt>
                    <dd className="numeric font-semibold text-slate-900">
                      {formatAmountString(data.loans.total_outstanding_principal)}
                    </dd>
                  </div>
                  <div className="flex items-center justify-between text-sm">
                    <dt className="font-medium text-slate-900">Outstanding (principal + interest)</dt>
                    <dd className="numeric font-semibold text-slate-900">
                      {formatAmountString(data.loans.total_outstanding)}
                    </dd>
                  </div>
                  <div className="flex items-center justify-between text-sm">
                    <dt className="font-medium text-slate-900">Principal + interest (contractual)</dt>
                    <dd className="numeric font-semibold text-slate-900">
                      {formatAmountString(data.loans.total_principal_plus_interest)}
                    </dd>
                  </div>
                  <div className="flex items-center justify-between text-sm">
                    <dt className="font-medium text-slate-900">Capital sent out</dt>
                    <dd className="numeric font-semibold text-slate-900">
                      {formatAmountString(data.loans.total_capital_disbursed)}
                    </dd>
                  </div>
                  <div className="flex items-center justify-between text-sm">
                    <dt className="font-medium text-slate-900">Expected interest</dt>
                    <dd className="numeric font-semibold text-slate-900">
                      {formatAmountString(data.loans.total_expected_interest)}
                    </dd>
                  </div>
                </div>
              </Card>
            </section>

            <section className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <StatCard
                label="Repayments awaiting verification"
                value={formatNumber(data.repayments.pending_verification)}
              />
              <StatCard
                label="Repayments awaiting approval"
                value={formatNumber(data.repayments.pending_approval)}
              />
              <StatCard
                label="Collected this month"
                value={formatAmountString(data.repayments.collected_this_month)}
                tone="success"
              />
            </section>

            <Card>
              <h2 className="text-sm font-semibold text-slate-900">Portfolio at risk</h2>
              <p className="mt-1 text-xs text-slate-500">
                Loans with an instalment overdue by {data.portfolio_at_risk.threshold_days}+ days.
              </p>
              <div className="mt-4 flex items-baseline gap-3">
                <span className="numeric text-2xl font-semibold text-danger">
                  {formatAmountString(data.portfolio_at_risk.outstanding_principal)}
                </span>
                <span className="numeric text-sm text-slate-500">
                  {formatPercentage(data.portfolio_at_risk.percentage_of_portfolio)} of outstanding
                  principal
                </span>
              </div>
            </Card>
          </div>
        ) : null}
      </QueryState>
    </>
  );
}
