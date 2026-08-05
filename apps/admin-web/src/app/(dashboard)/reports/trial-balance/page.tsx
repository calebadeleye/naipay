'use client';

import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Field } from '@/components/ui/field';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { formatAmountString } from '@/lib/format';
import { useTrialBalance } from '@/lib/reports/use-reports';
import type { TrialBalanceAccount } from '@/lib/reports/types';

const typeLabels: Record<TrialBalanceAccount['type'], string> = {
  asset: 'Asset',
  liability: 'Liability',
  equity: 'Equity',
  income: 'Income',
  expense: 'Expense',
};

export default function TrialBalancePage() {
  const [asOf, setAsOf] = useState('');
  const { data, isLoading, error } = useTrialBalance(asOf || undefined);

  return (
    <>
      <PageHeader
        title="Trial balance"
        description="Every ledger account, derived from journal entries directly rather than the cached running balance — trustworthy for a past date, not only today."
        actions={
          <Field
            label="As of"
            type="date"
            value={asOf}
            onChange={(event) => setAsOf(event.target.value)}
            className="w-40"
          />
        }
      />

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <div className="space-y-4">
            <div
              className={
                data.is_balanced
                  ? 'rounded-md border border-green-200 bg-success-surface px-4 py-2.5 text-sm text-success'
                  : 'rounded-md border border-red-200 bg-danger-surface px-4 py-2.5 text-sm text-danger'
              }
            >
              {data.is_balanced
                ? 'Debits equal credits — every posting balances.'
                : 'Debits and credits do not agree. This should never happen; treat as an incident.'}
            </div>

            <Card className="overflow-x-auto p-0">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                    <th className="px-4 py-3">Code</th>
                    <th className="px-4 py-3">Account</th>
                    <th className="px-4 py-3">Type</th>
                    <th className="px-4 py-3 text-right">Debits</th>
                    <th className="px-4 py-3 text-right">Credits</th>
                    <th className="px-4 py-3 text-right">Balance</th>
                  </tr>
                </thead>
                <tbody>
                  {data.accounts.map((account) => (
                    <tr key={account.code} className="border-b border-slate-100 last:border-0">
                      <td className="numeric px-4 py-3 text-slate-500">{account.code}</td>
                      <td className="px-4 py-3 font-medium text-slate-900">{account.name}</td>
                      <td className="px-4 py-3">
                        <Badge tone="neutral">{typeLabels[account.type]}</Badge>
                      </td>
                      <td className="numeric px-4 py-3 text-right text-slate-700">
                        {formatAmountString(account.total_debits)}
                      </td>
                      <td className="numeric px-4 py-3 text-right text-slate-700">
                        {formatAmountString(account.total_credits)}
                      </td>
                      <td className="numeric px-4 py-3 text-right font-medium text-slate-900">
                        {formatAmountString(account.balance)}
                      </td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t-2 border-slate-200 font-semibold text-slate-900">
                    <td className="px-4 py-3" colSpan={3}>
                      Total
                    </td>
                    <td className="numeric px-4 py-3 text-right">
                      {formatAmountString(data.total_debits)}
                    </td>
                    <td className="numeric px-4 py-3 text-right">
                      {formatAmountString(data.total_credits)}
                    </td>
                    <td />
                  </tr>
                </tfoot>
              </table>
            </Card>
          </div>
        ) : null}
      </QueryState>
    </>
  );
}
