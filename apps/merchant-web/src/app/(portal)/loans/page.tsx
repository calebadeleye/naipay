'use client';

import Link from 'next/link';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { QueryState } from '@/components/ui/query-state';
import { useLoanList } from '@/lib/loans/use-loans';
import type { LoanStatusKey } from '@/lib/loans/types';
import { formatDate, formatMoney } from '@/lib/format';

const statusTone: Record<LoanStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  pending_approval: 'neutral',
  pending_disbursement: 'warning',
  disbursed: 'success',
  written_off: 'danger',
};

export default function LoansPage() {
  const [page, setPage] = useState(1);
  const { data, isLoading, error } = useLoanList({ page, per_page: 20 });

  return (
    <>
      <PageHeader title="Loans" description="Every loan you've taken out with Every Merchant." />

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-4 py-3">Loan</th>
                  <th className="px-4 py-3">Taken</th>
                  <th className="px-4 py-3">Principal</th>
                  <th className="px-4 py-3">Outstanding</th>
                  <th className="px-4 py-3">Status</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((loan) => (
                  <tr key={loan.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link href={`/loans/${loan.id}`} className="font-medium text-brand-700 hover:underline">
                        {loan.loan_product?.name ?? 'Loan'}
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-slate-500">
                      {formatDate(loan.disbursement?.date ?? loan.created_at)}
                    </td>
                    <td className="numeric px-4 py-3 text-slate-700">{formatMoney(loan.terms.principal_amount)}</td>
                    <td className="numeric px-4 py-3 text-slate-700">
                      {loan.outstanding ? formatMoney(loan.outstanding.principal) : '—'}
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone[loan.status]}>{loan.status_label}</Badge>
                    </td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-4 py-10 text-center text-slate-500">
                      You have no loans yet.
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
