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
import { formatDate, formatDateTime } from '@/lib/format';
import { useHasPermission } from '@/lib/auth/use-permission';
import { useBranch, useChangeBranchStatus, useUpdateBranch } from '@/lib/branches/use-branches';
import type { Branch, BranchFormInput, BranchStatusKey } from '@/lib/branches/types';

const statusTone: Record<BranchStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  active: 'success',
  suspended: 'warning',
  closed: 'danger',
};

const statusTransitions: Record<BranchStatusKey, BranchStatusKey[]> = {
  active: ['suspended', 'closed'],
  suspended: ['active', 'closed'],
  closed: [],
};

const statusLabels: Record<BranchStatusKey, string> = {
  active: 'Active',
  suspended: 'Suspended',
  closed: 'Closed',
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

function toFormInput(branch: Branch): BranchFormInput {
  return {
    name: branch.name,
    address: branch.address ?? '',
    city: branch.city ?? '',
    state: branch.state ?? '',
    country: branch.country ?? '',
    phone: branch.phone ?? '',
    email: branch.email ?? '',
  };
}

export default function BranchDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: branch, isLoading, error } = useBranch(id);
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<BranchFormInput | null>(null);
  const canManage = useHasPermission('branches.manage');

  const updateBranch = useUpdateBranch(id);
  const changeStatus = useChangeBranchStatus(id);
  const updateError = updateBranch.error instanceof ApiError ? updateBranch.error : null;

  function startEditing(current: Branch) {
    setForm(toFormInput(current));
    setEditing(true);
  }

  function set<K extends keyof BranchFormInput>(key: K, value: BranchFormInput[K]) {
    if (!form) return;
    setForm({ ...form, [key]: value });
  }

  async function handleSave(event: React.FormEvent) {
    event.preventDefault();
    if (!form) return;

    try {
      await updateBranch.mutateAsync(form);
      setEditing(false);
    } catch {
      // Surfaced via `updateError` above, rendered from the mutation state.
    }
  }

  return (
    <QueryState isLoading={isLoading} error={error}>
      {branch ? (
        <div className="space-y-6">
          <PageHeader
            title={branch.name}
            description={branch.branch_code}
            actions={
              <div className="flex items-center gap-2">
                <Badge tone={statusTone[branch.status]}>{branch.status_label}</Badge>
                {!editing && canManage ? (
                  <Button type="button" variant="secondary" onClick={() => startEditing(branch)}>
                    Edit
                  </Button>
                ) : null}
              </div>
            }
          />

          {canManage ? (
            <Card>
              <h2 className="mb-4 text-sm font-semibold text-slate-900">Workflow</h2>
              <div className="flex flex-wrap items-start gap-3">
                {statusTransitions[branch.status].map((target) => (
                  <ReasonActionButton
                    key={target}
                    label={`Mark as ${statusLabels[target]}`}
                    variant={target === 'closed' || target === 'suspended' ? 'destructive' : 'secondary'}
                    reasonLabel="Reason"
                    onConfirm={(reason) => changeStatus.mutateAsync({ status: target, reason })}
                  />
                ))}
                {statusTransitions[branch.status].length === 0 ? (
                  <p className="text-sm text-slate-500">No further transitions — this branch is closed.</p>
                ) : null}
              </div>
              <p className="mt-3 text-xs text-slate-500">
                There is no delete: every loan and repayment booked here names this branch permanently.
              </p>
            </Card>
          ) : null}

          {editing && form ? (
            <Card className="max-w-2xl">
              <div className="mb-4 flex items-center justify-between">
                <h2 className="text-sm font-semibold text-slate-900">Edit branch</h2>
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
                <Field
                  label="Address"
                  value={form.address}
                  onChange={(event) => set('address', event.target.value)}
                  error={updateError?.fieldError('address')}
                />
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <Field
                    label="City"
                    value={form.city}
                    onChange={(event) => set('city', event.target.value)}
                    error={updateError?.fieldError('city')}
                  />
                  <Field
                    label="State"
                    value={form.state}
                    onChange={(event) => set('state', event.target.value)}
                    error={updateError?.fieldError('state')}
                  />
                  <Field
                    label="Country"
                    value={form.country}
                    onChange={(event) => set('country', event.target.value)}
                    error={updateError?.fieldError('country')}
                  />
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field
                    label="Phone"
                    value={form.phone}
                    onChange={(event) => set('phone', event.target.value)}
                    error={updateError?.fieldError('phone')}
                  />
                  <Field
                    label="Email"
                    type="email"
                    value={form.email}
                    onChange={(event) => set('email', event.target.value)}
                    error={updateError?.fieldError('email')}
                  />
                </div>
                <Button type="submit" loading={updateBranch.isPending}>
                  Save changes
                </Button>
              </form>
            </Card>
          ) : (
            <Card className="max-w-2xl">
              <h2 className="mb-4 text-sm font-semibold text-slate-900">Details</h2>
              <div className="grid grid-cols-2 gap-4">
                <Detail label="Address" value={branch.address} />
                <Detail label="City" value={branch.city} />
                <Detail label="State" value={branch.state} />
                <Detail label="Country" value={branch.country} />
                <Detail label="Phone" value={branch.phone} />
                <Detail label="Email" value={branch.email} />
                <Detail label="Manager" value={branch.manager?.full_name} />
                <Detail label="Staff" value={branch.staff_count} />
                <Detail label="Opened" value={formatDate(branch.opened_at)} />
              </div>
            </Card>
          )}

          <p className="text-xs text-slate-400">Last updated {formatDateTime(branch.updated_at)}</p>
        </div>
      ) : null}
    </QueryState>
  );
}
