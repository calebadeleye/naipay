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
import { useInvestorList } from '@/lib/investors/use-investors';
import type { InvestorStatusKey } from '@/lib/investors/types';

const statusTone: Record<InvestorStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  active: 'success',
  suspended: 'warning',
  disabled: 'danger',
};

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'disabled', label: 'Disabled' },
];

export default function InvestorsPage() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const canCreate = useHasPermission('investors.create');

  const { data, isLoading, error } = useInvestorList({
    search: search || undefined,
    status: status || undefined,
    page,
    per_page: 20,
  });

  return (
    <>
      <PageHeader
        title="Investors"
        description="External stakeholders with access to the read-only investor dashboard."
        actions={
          canCreate ? (
            <Link href="/investors/new" className={buttonVariants({ variant: 'primary' })}>
              New investor
            </Link>
          ) : undefined
        }
      />

      <div className="flex flex-wrap gap-3">
        <Field
          label="Search"
          placeholder="Name, email or investor number"
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
                  <th className="px-4 py-3">Investor</th>
                  <th className="px-4 py-3">Investor number</th>
                  <th className="px-4 py-3">Status</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((investor) => (
                  <tr key={investor.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link href={`/investors/${investor.id}`} className="font-medium text-brand-700 hover:underline">
                        {investor.name}
                      </Link>
                      <p className="text-xs text-slate-500">{investor.email}</p>
                    </td>
                    <td className="px-4 py-3 text-slate-700">{investor.investor_number}</td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone[investor.status]}>{investor.status_label}</Badge>
                    </td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={3} className="px-4 py-10 text-center text-slate-500">
                      No investors match these filters.
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
