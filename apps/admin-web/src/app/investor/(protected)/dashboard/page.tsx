'use client';

import { Card, StatCard } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { PortfolioAllocationChart } from '@/components/investor/portfolio-allocation-chart';
import { RevenueProfitChart } from '@/components/investor/revenue-profit-chart';
import { useInvestorDashboard } from '@/lib/investor-dashboard/use-investor-dashboard';
import {
  formatAmountString,
  formatCompactMoney,
  formatDate,
  formatNumber,
  formatPercentage,
} from '@/lib/format';

export default function InvestorDashboardPage() {
  const { data, isLoading, error } = useInvestorDashboard();

  return (
    <>
      <PageHeader
        title="Investor Dashboard"
        description="Portfolio performance and investment overview."
      />

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <div className="space-y-6">
            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
              <StatCard
                label="Total investment portfolio"
                value={formatCompactMoney(data.total_investment_portfolio)}
              />
              <StatCard label="Total revenue (MTD)" value={formatCompactMoney(data.total_revenue_mtd)} />
              <StatCard label="Net profit (MTD)" value={formatCompactMoney(data.net_profit_mtd)} tone="success" />
              <StatCard label="ROI (YTD)" value={formatPercentage(data.roi_ytd_percentage)} />
              <StatCard label="Number of debtors" value={formatNumber(data.number_of_debtors)} />
            </section>

            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
              <StatCard label="Active loans" value={formatNumber(data.active_loans)} />
              <StatCard
                label="Outstanding loan balance"
                value={formatCompactMoney(data.outstanding_loan_balance)}
              />
              <StatCard
                label="Collections this month"
                value={formatCompactMoney(data.collections_this_month)}
                tone="success"
              />
              <StatCard
                label="Default rate"
                value={formatPercentage(data.default_rate_percentage)}
                tone={data.default_rate_percentage > 5 ? 'danger' : 'default'}
              />
              <StatCard
                label="Portfolio growth (YTD)"
                value={formatPercentage(data.portfolio_growth_ytd_percentage)}
                tone="success"
              />
            </section>

            <section className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              <Card>
                <h2 className="text-sm font-semibold text-slate-900">Revenue &amp; profit trend</h2>
                <div className="mt-4">
                  <RevenueProfitChart data={data.revenue_and_profit_trend} />
                </div>
              </Card>

              <Card>
                <h2 className="text-sm font-semibold text-slate-900">Portfolio allocation</h2>
                <p className="mt-1 text-xs text-slate-500">Outstanding principal by loan product.</p>
                <div className="mt-4">
                  <PortfolioAllocationChart data={data.portfolio_allocation} />
                </div>
              </Card>
            </section>

            <section className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              <Card>
                <h2 className="text-sm font-semibold text-slate-900">Top performing branches</h2>
                <div className="mt-4 overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-slate-100 text-left text-xs font-medium tracking-wide text-slate-500 uppercase">
                        <th className="pb-2 pr-4">Branch</th>
                        <th className="pb-2 pr-4">Credit portfolio</th>
                        <th className="pb-2">Growth (MTD)</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.top_performing_branches.map((branch) => (
                        <tr key={branch.branch_id} className="border-b border-slate-50 last:border-0">
                          <td className="py-2 pr-4 text-slate-800">{branch.name}</td>
                          <td className="numeric py-2 pr-4 font-medium text-slate-900">
                            {formatAmountString(branch.credit_portfolio)}
                          </td>
                          <td className="numeric py-2 text-success">
                            {formatPercentage(branch.growth_mtd_percentage)}
                          </td>
                        </tr>
                      ))}

                      {data.top_performing_branches.length === 0 ? (
                        <tr>
                          <td colSpan={3} className="py-4 text-center text-sm text-slate-500">
                            No disbursed loans yet.
                          </td>
                        </tr>
                      ) : null}
                    </tbody>
                  </table>
                </div>
              </Card>

              <Card>
                <h2 className="text-sm font-semibold text-slate-900">Recent repayments</h2>
                <div className="mt-4 overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-slate-100 text-left text-xs font-medium tracking-wide text-slate-500 uppercase">
                        <th className="pb-2 pr-4">Merchant</th>
                        <th className="pb-2 pr-4">Amount</th>
                        <th className="pb-2">Date</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.recent_repayments.map((repayment) => (
                        <tr key={repayment.id} className="border-b border-slate-50 last:border-0">
                          <td className="py-2 pr-4 text-slate-800">{repayment.merchant_name}</td>
                          <td className="numeric py-2 pr-4 font-medium text-slate-900">
                            {formatAmountString(repayment.amount)}
                          </td>
                          <td className="py-2 text-slate-500">{formatDate(repayment.payment_date)}</td>
                        </tr>
                      ))}

                      {data.recent_repayments.length === 0 ? (
                        <tr>
                          <td colSpan={3} className="py-4 text-center text-sm text-slate-500">
                            No repayments recorded yet.
                          </td>
                        </tr>
                      ) : null}
                    </tbody>
                  </table>
                </div>
              </Card>
            </section>
          </div>
        ) : null}
      </QueryState>
    </>
  );
}
