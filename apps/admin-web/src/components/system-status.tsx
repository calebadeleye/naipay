'use client';

import { ApiError, NetworkError } from '@naipay/api-client';
import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, CheckCircle2, Loader2, XCircle } from 'lucide-react';

import { api } from '@/lib/api';
import { cn } from '@/lib/cn';

interface HealthCheck {
  status: 'up' | 'down';
  error?: string;
}

interface HealthPayload {
  status: 'healthy' | 'degraded' | 'unhealthy';
  checks: Record<string, HealthCheck>;
}

/**
 * Live view of the API's dependency health.
 *
 * Doubles as the Phase 0 end-to-end proof: if this renders, the console's
 * environment is valid, the shared API client reached the backend, and the
 * response envelope decoded correctly.
 */
export function SystemStatus() {
  const { data, error, isPending } = useQuery({
    queryKey: ['system', 'health'],
    queryFn: ({ signal }) => api.get<HealthPayload>('/health', { signal }),
    refetchInterval: 30_000,
  });

  if (isPending) {
    return (
      <Panel>
        <div className="flex items-center gap-3 text-slate-600">
          <Loader2 className="size-5 animate-spin" aria-hidden />
          <span>Checking connection to the Naipay API…</span>
        </div>
      </Panel>
    );
  }

  if (error) {
    const message =
      error instanceof ApiError || error instanceof NetworkError
        ? error.message
        : 'The Naipay API could not be reached.';

    return (
      <Panel tone="danger">
        <div className="flex items-start gap-3">
          <XCircle className="mt-0.5 size-5 shrink-0 text-danger" aria-hidden />
          <div className="space-y-1">
            <p className="font-medium text-slate-900">Cannot reach the Naipay API</p>
            <p className="text-sm text-slate-600">{message}</p>
            {error instanceof ApiError && error.correlationId ? (
              <p className="numeric text-xs text-slate-500">
                Reference {error.correlationId}
              </p>
            ) : null}
          </div>
        </div>
      </Panel>
    );
  }

  const degraded = data.status !== 'healthy';

  return (
    <Panel tone={degraded ? 'warning' : 'success'}>
      <div className="space-y-4">
        <div className="flex items-center gap-3">
          {degraded ? (
            <AlertTriangle className="size-5 text-warning" aria-hidden />
          ) : (
            <CheckCircle2 className="size-5 text-success" aria-hidden />
          )}
          <p className="font-medium text-slate-900">
            {degraded ? 'Some services are degraded' : 'All services are operating normally'}
          </p>
        </div>

        <dl className="grid gap-2 sm:grid-cols-3">
          {Object.entries(data.checks).map(([name, check]) => (
            <div
              key={name}
              className="rounded-md border border-slate-200 bg-white px-3 py-2"
            >
              <dt className="text-xs font-medium tracking-wide text-slate-500 uppercase">
                {name}
              </dt>
              <dd
                className={cn(
                  'mt-0.5 text-sm font-medium',
                  check.status === 'up' ? 'text-success' : 'text-danger',
                )}
              >
                {check.status === 'up' ? 'Operational' : 'Unavailable'}
              </dd>
            </div>
          ))}
        </dl>
      </div>
    </Panel>
  );
}

function Panel({
  children,
  tone = 'neutral',
}: {
  children: React.ReactNode;
  tone?: 'neutral' | 'success' | 'warning' | 'danger';
}) {
  return (
    <section
      className={cn(
        'rounded-lg border p-5',
        tone === 'neutral' && 'border-slate-200 bg-white',
        tone === 'success' && 'border-brand-200 bg-brand-50',
        tone === 'warning' && 'border-amber-200 bg-warning-surface',
        tone === 'danger' && 'border-red-200 bg-danger-surface',
      )}
    >
      {children}
    </section>
  );
}
