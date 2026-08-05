'use client';

import { Card, StatCard } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { formatAmountString, formatNumber, formatPercentage } from '@/lib/format';
import { useDelinquency } from '@/lib/reports/use-reports';

export default function DelinquencyPage() {
  const { data, isLoading, error } = useDelinquency();

  return (
    <>
      <PageHeader
        title="Delinquency"
        description="Disbursed loans bucketed by how many days their earliest unpaid instalment has been overdue."
      />

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <div className="space-y-6">
            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <StatCard
                label="Portfolio at risk"
                value={formatAmountString(data.portfolio_at_risk.outstanding_principal)}
                hint={`${data.portfolio_at_risk.threshold_days}+ days overdue — ${formatPercentage(data.portfolio_at_risk.percentage_of_portfolio)} of outstanding principal`}
                tone="danger"
              />
              <StatCard
                label="Current"
                value={formatAmountString(data.current.outstanding_principal)}
                hint={`${formatNumber(data.current.count)} loans with no overdue instalment`}
                tone="success"
              />
            </section>

            <Card className="overflow-x-auto p-0">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                    <th className="px-4 py-3">Ageing bucket</th>
                    <th className="px-4 py-3 text-right">Loans</th>
                    <th className="px-4 py-3 text-right">Outstanding principal</th>
                  </tr>
                </thead>
                <tbody>
                  {data.ageing_buckets.map((bucket) => (
                    <tr key={bucket.label} className="border-b border-slate-100 last:border-0">
                      <td className="px-4 py-3 font-medium text-slate-900">{bucket.label}</td>
                      <td className="numeric px-4 py-3 text-right text-slate-700">
                        {formatNumber(bucket.count)}
                      </td>
                      <td className="numeric px-4 py-3 text-right text-slate-700">
                        {formatAmountString(bucket.outstanding_principal)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          </div>
        ) : null}
      </QueryState>
    </>
  );
}
