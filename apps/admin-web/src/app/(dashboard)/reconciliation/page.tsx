'use client';

import Link from 'next/link';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { QueryState } from '@/components/ui/query-state';
import { SelectField } from '@/components/ui/select';
import { useHasPermission } from '@/lib/auth/use-permission';
import { formatDate, formatMoney } from '@/lib/format';
import { useReconciliations } from '@/lib/reconciliation/use-reconciliation';
import type { BankReconciliationStatusKey } from '@/lib/reconciliation/types';

const statusTone: Record<BankReconciliationStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  in_progress: 'neutral',
  pending_approval: 'warning',
  approved: 'success',
};

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'pending_approval', label: 'Pending approval' },
  { value: 'approved', label: 'Approved' },
];

export default function ReconciliationPage() {
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const canCreate = useHasPermission('reconciliation.match');

  const { data, isLoading, error } = useReconciliations({
    status: status || undefined,
    page,
    per_page: 20,
  });

  return (
    <>
      <PageHeader
        title="Bank reconciliation"
        description="Manual, statement-by-statement: every line is transcribed and matched to a repayment or a loan disbursement."
        actions={
          canCreate ? (
            <Link href="/reconciliation/new" className={buttonVariants({ variant: 'primary' })}>
              Open reconciliation
            </Link>
          ) : undefined
        }
      />

      <div className="flex flex-wrap gap-3">
        <SelectField
          label="Status"
          options={statusOptions}
          value={status}
          onChange={(event) => {
            setPage(1);
            setStatus(event.target.value);
          }}
          className="w-56"
        />
      </div>

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-4 py-3">Bank account</th>
                  <th className="px-4 py-3">Period</th>
                  <th className="px-4 py-3 text-right">Closing balance</th>
                  <th className="px-4 py-3">Status</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((reconciliation) => (
                  <tr key={reconciliation.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link
                        href={`/reconciliation/${reconciliation.id}`}
                        className="font-medium text-brand-700 hover:underline"
                      >
                        {reconciliation.bank_account}
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-slate-700">
                      {formatDate(reconciliation.period_start)} – {formatDate(reconciliation.period_end)}
                    </td>
                    <td className="numeric px-4 py-3 text-right text-slate-700">
                      {formatMoney(reconciliation.statement_closing_balance)}
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone[reconciliation.status]}>{reconciliation.status_label}</Badge>
                    </td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="px-4 py-10 text-center text-slate-500">
                      No reconciliations match these filters.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
            <Pagination meta={data.pagination} page={page} onPageChange={setPage} />
          </Card>
        ) : null}
      </QueryState>
    </>
  );
}
