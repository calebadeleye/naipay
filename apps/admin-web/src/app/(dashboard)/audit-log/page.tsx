'use client';

import Link from 'next/link';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Field } from '@/components/ui/field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { QueryState } from '@/components/ui/query-state';
import { formatDateTime } from '@/lib/format';
import { useAuditLogs } from '@/lib/audit/use-audit';

export default function AuditLogPage() {
  const [search, setSearch] = useState('');
  const [module, setModule] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading, error } = useAuditLogs({
    search: search || undefined,
    module: module || undefined,
    page,
    per_page: 20,
  });

  return (
    <>
      <PageHeader
        title="Audit log"
        description="Every sensitive action across every domain, in one place. Read-only — an audit entry is never edited or removed."
      />

      <div className="flex flex-wrap gap-3">
        <Field
          label="Search"
          placeholder="Action, reference or actor"
          value={search}
          onChange={(event) => {
            setPage(1);
            setSearch(event.target.value);
          }}
          className="w-72"
        />
        <Field
          label="Module"
          placeholder="e.g. loans, merchants"
          value={module}
          onChange={(event) => {
            setPage(1);
            setModule(event.target.value);
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
                  <th className="px-4 py-3">When</th>
                  <th className="px-4 py-3">Action</th>
                  <th className="px-4 py-3">Module</th>
                  <th className="px-4 py-3">Reference</th>
                  <th className="px-4 py-3">Actor</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((log) => (
                  <tr key={log.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3 text-slate-500">{formatDateTime(log.created_at)}</td>
                    <td className="px-4 py-3">
                      <Link href={`/audit-log/${log.id}`} className="font-medium text-brand-700 hover:underline">
                        {log.action}
                      </Link>
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone="neutral">{log.module}</Badge>
                    </td>
                    <td className="numeric px-4 py-3 text-slate-700">{log.auditable_reference ?? '—'}</td>
                    <td className="px-4 py-3 text-slate-700">{log.actor.name ?? 'System'}</td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-4 py-10 text-center text-slate-500">
                      No audit entries match these filters.
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
