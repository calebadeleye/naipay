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
import { useLoanApplications } from '@/lib/loan-applications/use-loan-applications';
import type { LoanApplicationStatusKey } from '@/lib/loan-applications/types';

const statusTone: Record<LoanApplicationStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  draft: 'neutral',
  submitted: 'info',
  under_assessment: 'warning',
  recommended: 'warning',
  approved: 'success',
  rejected: 'danger',
  withdrawn: 'neutral',
  expired: 'danger',
};

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'submitted', label: 'Submitted' },
  { value: 'under_assessment', label: 'Under assessment' },
  { value: 'recommended', label: 'Recommended' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'withdrawn', label: 'Withdrawn' },
  { value: 'expired', label: 'Expired' },
];

export default function LoanApplicationsPage() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const canCreate = useHasPermission('loan_applications.create');

  const { data, isLoading, error } = useLoanApplications({
    search: search || undefined,
    status: status || undefined,
    page,
    per_page: 20,
  });

  return (
    <>
      <PageHeader
        title="Loan applications"
        description="Applications moving through assessment, recommendation and approval."
        actions={
          canCreate ? (
            <Link href="/loan-applications/new" className={buttonVariants({ variant: 'primary' })}>
              New application
            </Link>
          ) : undefined
        }
      />

      <div className="flex flex-wrap gap-3">
        <Field
          label="Search"
          placeholder="Application number"
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
                  <th className="px-4 py-3">Application</th>
                  <th className="px-4 py-3">Merchant</th>
                  <th className="px-4 py-3">Product</th>
                  <th className="px-4 py-3 text-right">Requested</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Created</th>
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
                    </td>
                    <td className="px-4 py-3 text-slate-700">{application.merchant?.full_name ?? '—'}</td>
                    <td className="px-4 py-3 text-slate-700">{application.loan_product?.name ?? '—'}</td>
                    <td className="numeric px-4 py-3 text-right text-slate-700">
                      {formatMoney(application.requested.amount)}
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone[application.status]}>{application.status_label}</Badge>
                    </td>
                    <td className="px-4 py-3 text-slate-500">{formatDate(application.created_at)}</td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                      No loan applications match these filters.
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
