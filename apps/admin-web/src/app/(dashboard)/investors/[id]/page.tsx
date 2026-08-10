'use client';

import { ApiError } from '@naipay/api-client';
import { useParams } from 'next/navigation';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { ReasonActionButton } from '@/components/ui/workflow-action';
import { formatDateTime } from '@/lib/format';
import {
  useInvestor,
  useReinstateInvestor,
  useSuspendInvestor,
  useUpdateInvestor,
} from '@/lib/investors/use-investors';
import type { Investor, InvestorFormInput, InvestorStatusKey } from '@/lib/investors/types';

const statusTone: Record<InvestorStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  active: 'success',
  suspended: 'warning',
  disabled: 'danger',
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

function toFormInput(investor: Investor): InvestorFormInput {
  return {
    name: investor.name,
    email: investor.email,
    phone: investor.phone ?? '',
  };
}

export default function InvestorDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: investor, isLoading, error } = useInvestor(id);
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<InvestorFormInput | null>(null);

  const updateInvestor = useUpdateInvestor(id);
  const updateError = updateInvestor.error instanceof ApiError ? updateInvestor.error : null;

  const suspend = useSuspendInvestor(id);
  const reinstate = useReinstateInvestor(id);

  function startEditing(current: Investor) {
    setForm(toFormInput(current));
    setEditing(true);
  }

  function set<K extends keyof InvestorFormInput>(key: K, value: InvestorFormInput[K]) {
    if (!form) return;
    setForm({ ...form, [key]: value });
  }

  async function handleSave(event: React.FormEvent) {
    event.preventDefault();
    if (!form) return;

    try {
      await updateInvestor.mutateAsync(form);
      setEditing(false);
    } catch {
      // Surfaced via `updateError` above, rendered from the mutation state.
    }
  }

  return (
    <QueryState isLoading={isLoading} error={error}>
      {investor ? (
        <div className="space-y-6">
          <PageHeader
            title={investor.name}
            description={`${investor.investor_number} · ${investor.email}`}
            actions={
              <div className="flex items-center gap-2">
                <Badge tone={statusTone[investor.status]}>{investor.status_label}</Badge>
                {!editing ? (
                  <Button type="button" variant="secondary" onClick={() => startEditing(investor)}>
                    Edit
                  </Button>
                ) : null}
              </div>
            }
          />

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Workflow</h2>
            <div className="flex flex-wrap items-start gap-3">
              {investor.status === 'active' ? (
                <ReasonActionButton
                  label="Suspend"
                  reasonLabel="Reason for suspension"
                  onConfirm={(reason) => suspend.mutateAsync({ reason })}
                />
              ) : null}
              {investor.status === 'suspended' ? (
                <ReasonActionButton
                  label="Reinstate"
                  variant="secondary"
                  reasonLabel="Reason for reinstatement"
                  onConfirm={(reason) => reinstate.mutateAsync({ reason })}
                />
              ) : null}
            </div>
            {investor.suspension_reason ? (
              <p className="mt-4 text-sm text-danger">Suspension reason: {investor.suspension_reason}</p>
            ) : null}
          </Card>

          {editing && form ? (
            <Card className="max-w-2xl">
              <div className="mb-4 flex items-center justify-between">
                <h2 className="text-sm font-semibold text-slate-900">Edit investor</h2>
                <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(false)}>
                  Cancel
                </Button>
              </div>
              <form onSubmit={handleSave} className="space-y-5">
                {updateError && !updateError.isValidation ? (
                  <Alert tone="error" reference={updateError.correlationId}>
                    {updateError.message}
                  </Alert>
                ) : null}
                <Field
                  label="Name"
                  required
                  value={form.name}
                  onChange={(event) => set('name', event.target.value)}
                  error={updateError?.fieldError('name')}
                />
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field
                    label="Email"
                    type="email"
                    value={form.email}
                    onChange={(event) => set('email', event.target.value)}
                    error={updateError?.fieldError('email')}
                  />
                  <Field
                    label="Phone"
                    value={form.phone}
                    onChange={(event) => set('phone', event.target.value)}
                    error={updateError?.fieldError('phone')}
                  />
                </div>
                <Button type="submit" loading={updateInvestor.isPending}>
                  Save changes
                </Button>
              </form>
            </Card>
          ) : (
            <Card className="max-w-2xl">
              <h2 className="mb-4 text-sm font-semibold text-slate-900">Details</h2>
              <div className="grid grid-cols-2 gap-4">
                <Detail label="Phone" value={investor.phone} />
                <Detail label="Created by" value={investor.created_by?.name} />
                <Detail label="Last login" value={formatDateTime(investor.last_login_at)} />
                <Detail label="Failed sign-in attempts" value={investor.failed_login_attempts} />
              </div>
            </Card>
          )}

          <p className="text-xs text-slate-400">Last updated {formatDateTime(investor.updated_at)}</p>
        </div>
      ) : null}
    </QueryState>
  );
}
