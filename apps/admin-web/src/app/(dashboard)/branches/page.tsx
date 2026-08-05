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
import { useBranches } from '@/lib/branches/use-branches';
import type { BranchStatusKey } from '@/lib/branches/types';

const statusTone: Record<BranchStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  active: 'success',
  suspended: 'warning',
  closed: 'danger',
};

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'closed', label: 'Closed' },
];

export default function BranchesPage() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading, error } = useBranches({
    search: search || undefined,
    status: status || undefined,
    page,
    per_page: 20,
  });

  return (
    <>
      <PageHeader
        title="Branches"
        description="Naipay's physical locations. Staff and merchants are each attached to one."
        actions={
          <Link href="/branches/new" className={buttonVariants({ variant: 'primary' })}>
            New branch
          </Link>
        }
      />

      <div className="flex flex-wrap gap-3">
        <Field
          label="Search"
          placeholder="Code, name or city"
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
                  <th className="px-4 py-3">Branch</th>
                  <th className="px-4 py-3">Location</th>
                  <th className="px-4 py-3">Manager</th>
                  <th className="px-4 py-3">Status</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((branch) => (
                  <tr key={branch.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link href={`/branches/${branch.id}`} className="font-medium text-brand-700 hover:underline">
                        {branch.name}
                      </Link>
                      <p className="numeric text-xs text-slate-500">{branch.branch_code}</p>
                    </td>
                    <td className="px-4 py-3 text-slate-700">
                      {[branch.city, branch.state].filter(Boolean).join(', ') || '—'}
                    </td>
                    <td className="px-4 py-3 text-slate-700">{branch.manager?.full_name ?? '—'}</td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone[branch.status]}>{branch.status_label}</Badge>
                    </td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="px-4 py-10 text-center text-slate-500">
                      No branches match these filters.
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
