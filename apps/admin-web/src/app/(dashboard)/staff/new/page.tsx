'use client';

import { ApiError } from '@naipay/api-client';
import Link from 'next/link';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select';
import { ReauthPrompt } from '@/components/auth/reauth-prompt';
import { useProtectedAction } from '@/lib/auth/use-reauthenticate';
import { useBranchOptions } from '@/lib/branches/use-branches';
import { useCreateStaff } from '@/lib/staff/use-staff';
import { ROLE_OPTIONS } from '@/lib/staff/types';
import type { StaffFormInput, StaffMember } from '@/lib/staff/types';

const accessScopeOptions = [
  { value: 'branch', label: 'Own branch' },
  { value: 'department', label: 'Own department, all branches' },
  { value: 'global', label: 'Entire organisation' },
];

const emptyForm: StaffFormInput = {
  first_name: '',
  middle_name: '',
  last_name: '',
  email: '',
  username: '',
  phone: '',
  job_title: '',
  department: '',
  branch_id: '',
  access_scope: 'branch',
  roles: [],
};

export default function NewStaffPage() {
  const [form, setForm] = useState<StaffFormInput>(emptyForm);
  const { data: branchOptions } = useBranchOptions();
  const createStaff = useCreateStaff();

  const [created, setCreated] = useState<{ staff: StaffMember; temporary_password: string } | null>(null);

  // Assigning a role — even at creation — is gated by the same
  // reauthentication requirement as any other privilege grant.
  const protectedCreate = useProtectedAction((input: StaffFormInput) => createStaff.mutateAsync(input));
  const error = createStaff.error instanceof ApiError ? createStaff.error : protectedCreate.actionError;

  function set<K extends keyof StaffFormInput>(key: K, value: StaffFormInput[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  function toggleRole(role: string) {
    const roles = form.roles ?? [];
    set('roles', roles.includes(role) ? roles.filter((r) => r !== role) : [...roles, role]);
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();

    const result = await protectedCreate.run(form);

    if (result) {
      setCreated(result);
    }
  }

  if (protectedCreate.needsReauthentication) {
    return (
      <>
        <PageHeader title="New staff member" description="Confirm it is still you before granting a role." />
        <Card className="max-w-2xl">
          <ReauthPrompt
            pending={protectedCreate.reauthenticating}
            error={protectedCreate.reauthenticationError}
            onCancel={protectedCreate.cancelReauthentication}
            onConfirm={async (password, code) => {
              const result = await protectedCreate.confirmReauthentication(password, code);
              if (result) {
                setCreated(result);
              }
            }}
          />
        </Card>
      </>
    );
  }

  if (created) {
    return (
      <>
        <PageHeader title="Staff member created" description={created.staff.staff_number} />

        <Card className="max-w-2xl space-y-4">
          <Alert tone="success">
            {created.staff.full_name} has been created. Give them this temporary password — it is shown once, and
            they must change it at first sign-in.
          </Alert>

          <div className="rounded-md border border-slate-300 bg-slate-50 px-4 py-3">
            <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">Temporary password</p>
            <p className="numeric mt-1 text-lg font-semibold text-slate-900">{created.temporary_password}</p>
          </div>

          <Link href={`/staff/${created.staff.id}`} className="text-sm text-brand-700 hover:underline">
            View staff member
          </Link>
        </Card>
      </>
    );
  }

  return (
    <>
      <PageHeader
        title="New staff member"
        description="Creates the account with a temporary password, shown once. They must change it at first sign-in."
      />

      <Card className="max-w-2xl">
        <form onSubmit={handleSubmit} className="space-y-5">
          {error && !error.isValidation ? (
            <Alert tone="error" reference={error.correlationId}>
              {error.message}
            </Alert>
          ) : null}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Field
              label="First name"
              required
              value={form.first_name}
              onChange={(event) => set('first_name', event.target.value)}
              error={error?.fieldError('first_name')}
            />
            <Field
              label="Middle name"
              value={form.middle_name}
              onChange={(event) => set('middle_name', event.target.value)}
              error={error?.fieldError('middle_name')}
            />
            <Field
              label="Last name"
              required
              value={form.last_name}
              onChange={(event) => set('last_name', event.target.value)}
              error={error?.fieldError('last_name')}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="Email"
              type="email"
              required
              value={form.email}
              onChange={(event) => set('email', event.target.value)}
              error={error?.fieldError('email')}
            />
            <Field
              label="Username"
              value={form.username}
              onChange={(event) => set('username', event.target.value)}
              error={error?.fieldError('username')}
              hint="Optional. Lets them sign in without their email."
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="Phone"
              value={form.phone}
              onChange={(event) => set('phone', event.target.value)}
              error={error?.fieldError('phone')}
            />
            <Field
              label="Job title"
              value={form.job_title}
              onChange={(event) => set('job_title', event.target.value)}
              error={error?.fieldError('job_title')}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="Department"
              value={form.department}
              onChange={(event) => set('department', event.target.value)}
              error={error?.fieldError('department')}
            />
            <SelectField
              label="Branch"
              placeholder="No branch"
              options={(branchOptions ?? []).map((branch) => ({ value: String(branch.value), label: branch.label }))}
              value={form.branch_id ? String(form.branch_id) : ''}
              onChange={(event) => set('branch_id', event.target.value)}
              error={error?.fieldError('branch_id')}
            />
          </div>

          <SelectField
            label="Access scope"
            options={accessScopeOptions}
            value={form.access_scope}
            onChange={(event) => set('access_scope', event.target.value)}
            error={error?.fieldError('access_scope')}
            hint="How far across the organisation they can see, independent of what they can do."
          />

          <div className="rounded-md border border-slate-200 p-4">
            <h3 className="mb-3 text-sm font-semibold text-slate-900">Roles</h3>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {ROLE_OPTIONS.map((role) => (
                <label key={role.value} className="flex items-start gap-2 text-sm text-slate-800">
                  <input
                    type="checkbox"
                    className="mt-0.5 size-4 rounded border-slate-300"
                    checked={(form.roles ?? []).includes(role.value)}
                    onChange={() => toggleRole(role.value)}
                  />
                  <span>
                    <span className="font-medium">{role.label}</span>
                    <span className="block text-xs text-slate-500">{role.description}</span>
                  </span>
                </label>
              ))}
            </div>
            {error?.fieldError('roles') ? <p className="mt-2 text-xs text-danger">{error.fieldError('roles')}</p> : null}
          </div>

          <div className="flex gap-3">
            <Button type="submit" loading={protectedCreate.running}>
              Create staff member
            </Button>
          </div>
        </form>
      </Card>
    </>
  );
}
