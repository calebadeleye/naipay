'use client';

import Link from 'next/link';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { QueryState } from '@/components/ui/query-state';
import { useLoanApplicationList } from '@/lib/loan-applications/use-loan-applications';
import type { LoanApplicationStatusKey } from '@/lib/loan-applications/types';
import { formatMoney } from '@/lib/format';

const statusTone: Record<LoanApplicationStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  draft: 'neutral',
  submitted: 'info',
  under_assessment: 'info',
  recommended: 'info',
  approved: 'success',
  rejected: 'danger',
  withdrawn: 'neutral',
  expired: 'neutral',
};

export default function LoanApplicationsPage() {
  const [page, setPage] = useState(1);
  const { data, isLoading, error } = useLoanApplicationList({ page, per_page: 20 });

  return (
    <>
      <PageHeader
        title="Loan applications"
        description="Track the status of loans you've applied for."
        actions={
          <Link href="/loan-applications/new" className={buttonVariants({ variant: 'primary' })}>
            Apply for a loan
          </Link>
        }
      />

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-4 py-3">Application</th>
                  <th className="px-4 py-3">Amount</th>
                  <th className="px-4 py-3">Status</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((application) => (
                  <tr key={application.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link
                        href={`/loan-applications/${application.id}`}
                        className="numeric font-medium text-brand-700 hover:underline"
                      >
                        {application.application_number}
                      </Link>
                      <p className="text-xs text-slate-500">{application.loan_product?.name ?? '—'}</p>
                    </td>
                    <td className="numeric px-4 py-3 text-slate-700">{formatMoney(application.requested.amount)}</td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone[application.status]}>{application.status_label}</Badge>
                    </td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={3} className="px-4 py-10 text-center text-slate-500">
                      You haven&apos;t applied for a loan yet.
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
