'use client';

import Link from 'next/link';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field } from '@/components/ui/field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { QueryState } from '@/components/ui/query-state';
import { SelectField } from '@/components/ui/select';
import { useHasPermission } from '@/lib/auth/use-permission';
import { formatDate, formatMoney } from '@/lib/format';
import { useRepayments } from '@/lib/repayments/use-repayments';
import type { RepaymentStatusKey } from '@/lib/repayments/types';

const statusTone: Record<RepaymentStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  recorded: 'neutral',
  verified: 'info',
  approved: 'success',
  rejected: 'danger',
  reversed: 'danger',
};

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'recorded', label: 'Recorded' },
  { value: 'verified', label: 'Verified' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'reversed', label: 'Reversed' },
];

export default function RepaymentsPage() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const canCreate = useHasPermission('repayments.record');

  const { data, isLoading, error } = useRepayments({
    search: search || undefined,
    status: status || undefined,
    page,
    per_page: 20,
  });

  return (
    <>
      <PageHeader
        title="Repayments"
        description="Recording and verifying a repayment never moves money — only approval allocates it and posts the ledger entry."
        actions={
          canCreate ? (
            <Link href="/repayments/new" className={buttonVariants({ variant: 'primary' })}>
              Record repayment
            </Link>
          ) : undefined
        }
      />

      <div className="flex flex-wrap gap-3">
        <Field
          label="Search"
          placeholder="Repayment or bank reference"
          value={search}
          onChange={(event) => {
            setPage(1);
            setSearch(event.target.value);
          }}
          className="w-72"
        />
        <SelectField
          label="Status"
          options={statusOptions}
          value={status}
          onChange={(event) => {
            setPage(1);
            setStatus(event.target.value);
          }}
          className="w-48"
        />
      </div>

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-4 py-3">Repayment</th>
                  <th className="px-4 py-3">Merchant</th>
                  <th className="px-4 py-3">Loan</th>
                  <th className="px-4 py-3 text-right">Amount</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Payment date</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((repayment) => (
                  <tr key={repayment.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link
                        href={`/repayments/${repayment.id}`}
                        className="numeric font-medium text-brand-700 hover:underline"
                      >
                        {repayment.repayment_reference}
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-slate-700">{repayment.merchant?.full_name ?? '—'}</td>
                    <td className="numeric px-4 py-3 text-slate-700">{repayment.loan?.loan_reference ?? '—'}</td>
                    <td className="numeric px-4 py-3 text-right text-slate-700">{formatMoney(repayment.amount)}</td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone[repayment.status]}>{repayment.status_label}</Badge>
                    </td>
                    <td className="px-4 py-3 text-slate-500">{formatDate(repayment.payment_date)}</td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                      No repayments match these filters.
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
