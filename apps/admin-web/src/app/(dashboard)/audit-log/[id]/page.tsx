'use client';

import { useParams } from 'next/navigation';

import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { formatDateTime } from '@/lib/format';
import { useAuditLog } from '@/lib/audit/use-audit';

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'object') return JSON.stringify(value);

  return String(value);
}

export default function AuditLogDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: log, isLoading, error } = useAuditLog(id);

  return (
    <QueryState isLoading={isLoading} error={error}>
      {log ? (
        <div className="space-y-6">
          <PageHeader
            title={log.action}
            description={formatDateTime(log.created_at)}
            actions={<Badge tone="neutral">{log.module}</Badge>}
          />

          <Card className="max-w-2xl">
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Event</h2>
            <div className="grid grid-cols-2 gap-4">
              <Detail label="Event type" value={log.event_type} />
              <Detail label="Reference" value={log.auditable_reference} />
              <Detail label="Actor" value={log.actor.name ?? 'System'} />
              <Detail label="Roles" value={log.actor.roles} />
              <Detail label="IP address" value={log.ip_address} />
              <Detail label="Correlation ID" value={log.correlation_id} />
            </div>
            {log.reason ? (
              <>
                <h3 className="mt-4 text-xs font-medium tracking-wide text-slate-500 uppercase">Reason</h3>
                <p className="mt-1 text-sm text-slate-700">{log.reason}</p>
              </>
            ) : null}
          </Card>

          {log.changes ? (() => {
            const changedFields = Object.entries(log.changes).filter(
              ([, change]) => JSON.stringify(change.old) !== JSON.stringify(change.new),
            );

            return changedFields.length > 0 ? (
              <Card className="overflow-x-auto p-0">
                <h2 className="px-4 pt-4 text-sm font-semibold text-slate-900">Changes</h2>
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                      <th className="px-4 py-3">Field</th>
                      <th className="px-4 py-3">Before</th>
                      <th className="px-4 py-3">After</th>
                    </tr>
                  </thead>
                  <tbody>
                    {changedFields.map(([field, change]) => (
                      <tr key={field} className="border-b border-slate-100 last:border-0">
                        <td className="px-4 py-3 font-medium text-slate-900">{field}</td>
                        <td className="px-4 py-3 text-slate-500 line-through">{formatValue(change.old)}</td>
                        <td className="px-4 py-3 text-slate-900">{formatValue(change.new)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Card>
            ) : null;
          })() : null}
        </div>
      ) : null}
    </QueryState>
  );
}
