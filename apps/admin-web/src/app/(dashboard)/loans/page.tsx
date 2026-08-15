'use client';

import Link from 'next/link';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Field } from '@/components/ui/field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { QueryState } from '@/components/ui/query-state';
import { SelectField } from '@/components/ui/select';
import { formatDate, formatMoney } from '@/lib/format';
import { useLoans } from '@/lib/loans/use-loans';
import type { LoanStatusKey } from '@/lib/loans/types';

const statusTone: Record<LoanStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  pending_approval: 'warning',
  pending_disbursement: 'info',
  disbursed: 'success',
  written_off: 'danger',
};

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'pending_approval', label: 'Pending approval' },
  { value: 'pending_disbursement', label: 'Pending disbursement' },
  { value: 'disbursed', label: 'Disbursed' },
  { value: 'written_off', label: 'Written off' },
];

export default function LoansPage() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading, error } = useLoans({
    search: search || undefined,
    status: status || undefined,
    page,
    per_page: 20,
  });

  return (
    <>
      <PageHeader
        title="Loans"
        description="Loans move here automatically once their application is approved — there is no manual creation."
      />

      <div className="flex flex-wrap gap-3">
        <Field
          label="Search"
          placeholder="Account number, name, or loan reference"
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
          className="w-56"
        />
      </div>

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-4 py-3">Loan</th>
                  <th className="px-4 py-3">Merchant</th>
                  <th className="px-4 py-3">Product</th>
                  <th className="px-4 py-3 text-right">Principal</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Created</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((loan) => (
                  <tr key={loan.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link href={`/loans/${loan.id}`} className="numeric font-medium text-brand-700 hover:underline">
                        {loan.loan_reference}
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-slate-700">{loan.merchant?.full_name ?? '—'}</td>
                    <td className="px-4 py-3 text-slate-700">{loan.loan_product?.name ?? '—'}</td>
                    <td className="numeric px-4 py-3 text-right text-slate-700">
                      {formatMoney(loan.terms.principal_amount)}
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone[loan.status]}>{loan.status_label}</Badge>
                    </td>
                    <td className="px-4 py-3 text-slate-500">{formatDate(loan.created_at)}</td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                      No loans match these filters.
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
