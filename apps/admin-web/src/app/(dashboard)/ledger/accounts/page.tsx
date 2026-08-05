'use client';

import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { formatMoney } from '@/lib/format';
import { useChartOfAccounts } from '@/lib/ledger/use-ledger';
import type { AccountTypeKey } from '@/lib/ledger/types';

const typeTone: Record<AccountTypeKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  asset: 'info',
  liability: 'warning',
  equity: 'neutral',
  income: 'success',
  expense: 'danger',
};

export default function ChartOfAccountsPage() {
  const { data: accounts, isLoading, error } = useChartOfAccounts();

  return (
    <>
      <PageHeader
        title="Chart of accounts"
        description="Every ledger account and its current running balance."
      />

      <QueryState isLoading={isLoading} error={error}>
        {accounts ? (
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-4 py-3">Code</th>
                  <th className="px-4 py-3">Name</th>
                  <th className="px-4 py-3">Type</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3 text-right">Balance</th>
                </tr>
              </thead>
              <tbody>
                {accounts.map((account) => (
                  <tr key={account.id} className="border-b border-slate-100 last:border-0">
                    <td className="numeric px-4 py-3 text-slate-500">{account.code}</td>
                    <td className="px-4 py-3">
                      <p className="font-medium text-slate-900">{account.name}</p>
                      {account.description ? (
                        <p className="text-xs text-slate-500">{account.description}</p>
                      ) : null}
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={typeTone[account.type]}>{account.type_label}</Badge>
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={account.is_active ? 'success' : 'neutral'}>
                        {account.is_active ? 'Active' : 'Inactive'}
                      </Badge>
                    </td>
                    <td className="numeric px-4 py-3 text-right font-medium text-slate-900">
                      {formatMoney(account.balance)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
        ) : null}
      </QueryState>
    </>
  );
}
