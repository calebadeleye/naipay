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
import { useStaffList } from '@/lib/staff/use-staff';
import type { StaffStatusKey } from '@/lib/staff/types';

const statusTone: Record<StaffStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  pending_activation: 'neutral',
  active: 'success',
  suspended: 'warning',
  disabled: 'danger',
};

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'pending_activation', label: 'Pending activation' },
  { value: 'active', label: 'Active' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'disabled', label: 'Disabled' },
];

export default function StaffPage() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading, error } = useStaffList({
    search: search || undefined,
    status: status || undefined,
    page,
    per_page: 20,
  });

  return (
    <>
      <PageHeader
        title="Staff"
        description="Every officer with access to this console. Roles and approval limits decide what they can do, not the account itself."
        actions={
          <Link href="/staff/new" className={buttonVariants({ variant: 'primary' })}>
            New staff member
          </Link>
        }
      />

      <div className="flex flex-wrap gap-3">
        <Field
          label="Search"
          placeholder="Name, email or staff number"
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
                  <th className="px-4 py-3">Staff member</th>
                  <th className="px-4 py-3">Roles</th>
                  <th className="px-4 py-3">Branch</th>
                  <th className="px-4 py-3">Status</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((staff) => (
                  <tr key={staff.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link href={`/staff/${staff.id}`} className="font-medium text-brand-700 hover:underline">
                        {staff.full_name}
                      </Link>
                      <p className="text-xs text-slate-500">{staff.email}</p>
                    </td>
                    <td className="px-4 py-3 text-slate-700">{staff.roles.join(', ') || '—'}</td>
                    <td className="px-4 py-3 text-slate-700">{staff.branch?.name ?? '—'}</td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone[staff.status]}>{staff.status_label}</Badge>
                    </td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="px-4 py-10 text-center text-slate-500">
                      No staff match these filters.
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
