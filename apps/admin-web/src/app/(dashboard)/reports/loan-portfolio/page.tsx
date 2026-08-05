'use client';

import { Card, StatCard } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { formatAmountString, formatNumber } from '@/lib/format';
import { useLoanPortfolio } from '@/lib/reports/use-reports';
import type { LoanStatusKey } from '@/lib/reports/types';

const statusLabels: Record<LoanStatusKey, string> = {
  pending_approval: 'Pending approval',
  pending_disbursement: 'Pending disbursement',
  disbursed: 'Disbursed',
  written_off: 'Written off',
};

export default function LoanPortfolioPage() {
  const { data, isLoading, error } = useLoanPortfolio();

  return (
    <>
      <PageHeader
        title="Loan portfolio"
        description="Outstanding principal grouped by status and by product."
      />

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <div className="space-y-6">
            <StatCard
              label="Total outstanding principal"
              value={formatAmountString(data.total_outstanding_principal)}
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              <Card className="overflow-x-auto p-0">
                <div className="border-b border-slate-200 px-4 py-3">
                  <h2 className="text-sm font-semibold text-slate-900">By status</h2>
                </div>
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                      <th className="px-4 py-2">Status</th>
                      <th className="px-4 py-2 text-right">Loans</th>
                      <th className="px-4 py-2 text-right">Outstanding</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.by_status.map((row) => (
                      <tr key={row.status} className="border-t border-slate-100">
                        <td className="px-4 py-2.5 text-slate-700">
                          {statusLabels[row.status]}
                        </td>
                        <td className="numeric px-4 py-2.5 text-right text-slate-700">
                          {formatNumber(row.count)}
                        </td>
                        <td className="numeric px-4 py-2.5 text-right font-medium text-slate-900">
                          {formatAmountString(row.outstanding_principal)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Card>

              <Card className="overflow-x-auto p-0">
                <div className="border-b border-slate-200 px-4 py-3">
                  <h2 className="text-sm font-semibold text-slate-900">By product</h2>
                </div>
                {data.by_product.length === 0 ? (
                  <p className="px-4 py-6 text-sm text-slate-500">
                    No disbursed loans against any product yet.
                  </p>
                ) : (
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                        <th className="px-4 py-2">Product</th>
                        <th className="px-4 py-2 text-right">Loans</th>
                        <th className="px-4 py-2 text-right">Outstanding</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.by_product.map((row) => (
                        <tr key={row.loan_product_id} className="border-t border-slate-100">
                          <td className="px-4 py-2.5 text-slate-700">{row.name}</td>
                          <td className="numeric px-4 py-2.5 text-right text-slate-700">
                            {formatNumber(row.count)}
                          </td>
                          <td className="numeric px-4 py-2.5 text-right font-medium text-slate-900">
                            {formatAmountString(row.outstanding_principal)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </Card>
            </div>
          </div>
        ) : null}
      </QueryState>
    </>
  );
}
