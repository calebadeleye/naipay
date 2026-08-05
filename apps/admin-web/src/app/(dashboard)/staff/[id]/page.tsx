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
import { SelectField } from '@/components/ui/select';
import { TextareaField } from '@/components/ui/textarea';
import { ReasonActionButton } from '@/components/ui/workflow-action';
import { ReauthPrompt } from '@/components/auth/reauth-prompt';
import { formatDateTime, formatMoney } from '@/lib/format';
import { useProtectedAction } from '@/lib/auth/use-reauthenticate';
import { useBranchOptions } from '@/lib/branches/use-branches';
import {
  useAssignStaffRoles,
  useDisableStaff,
  useReinstateStaff,
  useResetStaffPassword,
  useSetStaffApprovalLimit,
  useStaffMember,
  useSuspendStaff,
  useTransferStaff,
  useUpdateStaff,
} from '@/lib/staff/use-staff';
import { ROLE_OPTIONS } from '@/lib/staff/types';
import type { StaffFormInput, StaffMember, StaffStatusKey } from '@/lib/staff/types';

const statusTone: Record<StaffStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  pending_activation: 'neutral',
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

function toFormInput(staff: StaffMember): StaffFormInput {
  return {
    first_name: staff.first_name,
    middle_name: staff.middle_name ?? '',
    last_name: staff.last_name,
    email: staff.email,
    username: staff.username ?? '',
    phone: staff.phone ?? '',
    job_title: staff.job_title ?? '',
    department: staff.department ?? '',
    access_scope: staff.access_scope,
  };
}

function TransferAction({ staffId }: { staffId: number }) {
  const [open, setOpen] = useState(false);
  const [branchId, setBranchId] = useState('');
  const [reason, setReason] = useState('');
  const { data: branchOptions } = useBranchOptions();
  const transfer = useTransferStaff(staffId);
  const error = transfer.error instanceof ApiError ? transfer.error : null;

  if (!open) {
    return (
      <Button type="button" variant="secondary" onClick={() => setOpen(true)}>
        Transfer branch
      </Button>
    );
  }

  return (
    <div className="w-full max-w-md space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4">
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}
      <SelectField
        label="New branch"
        placeholder="Select a branch"
        options={(branchOptions ?? []).map((branch) => ({ value: String(branch.value), label: branch.label }))}
        value={branchId}
        onChange={(event) => setBranchId(event.target.value)}
      />
      <TextareaField label="Reason" value={reason} onChange={(event) => setReason(event.target.value)} />
      <div className="flex gap-2">
        <Button
          type="button"
          loading={transfer.isPending}
          disabled={!branchId}
          onClick={() => transfer.mutateAsync({ branch_id: Number(branchId), reason }).then(() => setOpen(false))}
        >
          Confirm transfer
        </Button>
        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

function ResetPasswordAction({ staffId }: { staffId: number }) {
  const resetPassword = useResetStaffPassword(staffId);
  const error = resetPassword.error instanceof ApiError ? resetPassword.error : null;

  if (resetPassword.data) {
    return (
      <div className="w-full max-w-md space-y-2 rounded-md border border-slate-300 bg-slate-50 px-4 py-3">
        <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">
          Temporary password — shown once
        </p>
        <p className="numeric text-lg font-semibold text-slate-900">{resetPassword.data.temporary_password}</p>
      </div>
    );
  }

  return (
    <div className="inline-flex flex-col items-start gap-2">
      <Button type="button" variant="secondary" loading={resetPassword.isPending} onClick={() => resetPassword.mutate()}>
        Reset password
      </Button>
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}
    </div>
  );
}

function RolesEditor({ staff }: { staff: StaffMember }) {
  const [roles, setRoles] = useState<string[]>(staff.roles);
  const assignRoles = useAssignStaffRoles(staff.id);
  const protectedAssign = useProtectedAction((input: string[]) => assignRoles.mutateAsync(input));
  const error = protectedAssign.actionError;

  function toggle(role: string) {
    setRoles((current) => (current.includes(role) ? current.filter((r) => r !== role) : [...current, role]));
  }

  const dirty = JSON.stringify([...roles].sort()) !== JSON.stringify([...staff.roles].sort());

  if (protectedAssign.needsReauthentication) {
    return (
      <Card>
        <h2 className="mb-4 text-sm font-semibold text-slate-900">Roles</h2>
        <ReauthPrompt
          pending={protectedAssign.reauthenticating}
          error={protectedAssign.reauthenticationError}
          onCancel={protectedAssign.cancelReauthentication}
          onConfirm={(password, code) => protectedAssign.confirmReauthentication(password, code)}
        />
      </Card>
    );
  }

  return (
    <Card>
      <h2 className="mb-4 text-sm font-semibold text-slate-900">Roles</h2>
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        {ROLE_OPTIONS.map((role) => (
          <label key={role.value} className="flex items-start gap-2 text-sm text-slate-800">
            <input
              type="checkbox"
              className="mt-0.5 size-4 rounded border-slate-300"
              checked={roles.includes(role.value)}
              onChange={() => toggle(role.value)}
            />
            <span>
              <span className="font-medium">{role.label}</span>
              <span className="block text-xs text-slate-500">{role.description}</span>
            </span>
          </label>
        ))}
      </div>
      {dirty ? (
        <div className="mt-4">
          <Button type="button" loading={protectedAssign.running} onClick={() => protectedAssign.run(roles)}>
            Save roles
          </Button>
          <p className="mt-2 text-xs text-slate-500">This signs the staff member out of every active session.</p>
        </div>
      ) : null}
    </Card>
  );
}

function ApprovalLimitEditor({ staff }: { staff: StaffMember }) {
  const [amount, setAmount] = useState(staff.approval_limit?.amount ?? '');
  const [reason, setReason] = useState('');
  const setLimit = useSetStaffApprovalLimit(staff.id);
  const error = setLimit.error instanceof ApiError ? setLimit.error : null;

  async function handleSave() {
    try {
      await setLimit.mutateAsync({ approval_limit: amount || null, reason });
      setReason('');
    } catch {
      // Surfaced via `error` below, rendered from the mutation state.
    }
  }

  return (
    <Card>
      <h2 className="mb-4 text-sm font-semibold text-slate-900">Approval limit</h2>
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field
          label="Limit"
          placeholder="Leave blank to remove authority"
          value={amount}
          onChange={(event) => setAmount(event.target.value)}
          error={error?.fieldError('approval_limit')}
        />
        <Field
          label="Reason"
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          error={error?.fieldError('reason')}
        />
      </div>
      <div className="mt-4">
        <Button type="button" loading={setLimit.isPending} disabled={!reason} onClick={handleSave}>
          Save limit
        </Button>
      </div>
    </Card>
  );
}

export default function StaffDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: staff, isLoading, error } = useStaffMember(id);
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<StaffFormInput | null>(null);

  const updateStaff = useUpdateStaff(id);
  const updateError = updateStaff.error instanceof ApiError ? updateStaff.error : null;

  const suspend = useSuspendStaff(id);
  const reinstate = useReinstateStaff(id);
  const disable = useDisableStaff(id);

  function startEditing(current: StaffMember) {
    setForm(toFormInput(current));
    setEditing(true);
  }

  function set<K extends keyof StaffFormInput>(key: K, value: StaffFormInput[K]) {
    if (!form) return;
    setForm({ ...form, [key]: value });
  }

  async function handleSave(event: React.FormEvent) {
    event.preventDefault();
    if (!form) return;

    try {
      await updateStaff.mutateAsync(form);
      setEditing(false);
    } catch {
      // Surfaced via `updateError` above, rendered from the mutation state.
    }
  }

  return (
    <QueryState isLoading={isLoading} error={error}>
      {staff ? (
        <div className="space-y-6">
          <PageHeader
            title={staff.full_name}
            description={`${staff.staff_number} · ${staff.email}`}
            actions={
              <div className="flex items-center gap-2">
                <Badge tone={statusTone[staff.status]}>{staff.status_label}</Badge>
                {!editing ? (
                  <Button type="button" variant="secondary" onClick={() => startEditing(staff)}>
                    Edit
                  </Button>
                ) : null}
              </div>
            }
          />

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Workflow</h2>
            <div className="flex flex-wrap items-start gap-3">
              {staff.status === 'active' ? (
                <ReasonActionButton
                  label="Suspend"
                  reasonLabel="Reason for suspension"
                  onConfirm={(reason) => suspend.mutateAsync({ reason })}
                />
              ) : null}
              {staff.status === 'suspended' ? (
                <ReasonActionButton
                  label="Reinstate"
                  variant="secondary"
                  reasonLabel="Reason for reinstatement"
                  onConfirm={(reason) => reinstate.mutateAsync({ reason })}
                />
              ) : null}
              {staff.status !== 'disabled' ? (
                <ReasonActionButton
                  label="Disable"
                  reasonLabel="Reason for disabling this account"
                  onConfirm={(reason) => disable.mutateAsync({ reason })}
                />
              ) : null}
              <TransferAction staffId={staff.id} />
              <ResetPasswordAction staffId={staff.id} />
            </div>
            {staff.suspension_reason ? (
              <p className="mt-4 text-sm text-danger">Suspension reason: {staff.suspension_reason}</p>
            ) : null}
          </Card>

          {editing && form ? (
            <Card className="max-w-2xl">
              <div className="mb-4 flex items-center justify-between">
                <h2 className="text-sm font-semibold text-slate-900">Edit staff member</h2>
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
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <Field
                    label="First name"
                    required
                    value={form.first_name}
                    onChange={(event) => set('first_name', event.target.value)}
                    error={updateError?.fieldError('first_name')}
                  />
                  <Field
                    label="Middle name"
                    value={form.middle_name}
                    onChange={(event) => set('middle_name', event.target.value)}
                    error={updateError?.fieldError('middle_name')}
                  />
                  <Field
                    label="Last name"
                    required
                    value={form.last_name}
                    onChange={(event) => set('last_name', event.target.value)}
                    error={updateError?.fieldError('last_name')}
                  />
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field
                    label="Email"
                    type="email"
                    value={form.email}
                    onChange={(event) => set('email', event.target.value)}
                    error={updateError?.fieldError('email')}
                  />
                  <Field
                    label="Username"
                    value={form.username}
                    onChange={(event) => set('username', event.target.value)}
                    error={updateError?.fieldError('username')}
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
                    label="Job title"
                    value={form.job_title}
                    onChange={(event) => set('job_title', event.target.value)}
                    error={updateError?.fieldError('job_title')}
                  />
                </div>
                <Button type="submit" loading={updateStaff.isPending}>
                  Save changes
                </Button>
              </form>
            </Card>
          ) : (
            <Card className="max-w-2xl">
              <h2 className="mb-4 text-sm font-semibold text-slate-900">Details</h2>
              <div className="grid grid-cols-2 gap-4">
                <Detail label="Job title" value={staff.job_title} />
                <Detail label="Department" value={staff.department} />
                <Detail label="Phone" value={staff.phone} />
                <Detail label="Branch" value={staff.branch?.name} />
                <Detail label="Access scope" value={staff.access_scope_label} />
                <Detail label="Approval limit" value={staff.approval_limit ? formatMoney(staff.approval_limit) : 'None'} />
                <Detail label="Two-factor" value={staff.two_factor.enabled ? 'Enabled' : staff.two_factor.required ? 'Required, not yet set up' : 'Not enabled'} />
                <Detail label="Last login" value={formatDateTime(staff.last_login_at)} />
              </div>
            </Card>
          )}

          <RolesEditor staff={staff} />
          <ApprovalLimitEditor staff={staff} />

          <p className="text-xs text-slate-400">Last updated {formatDateTime(staff.updated_at)}</p>
        </div>
      ) : null}
    </QueryState>
  );
}
