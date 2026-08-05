'use client';

import { ApiError } from '@naipay/api-client';
import { useRouter } from 'next/navigation';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { useCreateBranch } from '@/lib/branches/use-branches';
import type { BranchFormInput } from '@/lib/branches/types';

const emptyForm: BranchFormInput = {
  name: '',
  address: '',
  city: '',
  state: '',
  country: 'Nigeria',
  phone: '',
  email: '',
};

export default function NewBranchPage() {
  const router = useRouter();
  const [form, setForm] = useState<BranchFormInput>(emptyForm);
  const createBranch = useCreateBranch();
  const error = createBranch.error instanceof ApiError ? createBranch.error : null;

  function set<K extends keyof BranchFormInput>(key: K, value: BranchFormInput[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();

    try {
      const branch = await createBranch.mutateAsync(form);
      router.replace(`/branches/${branch.id}`);
    } catch {
      // Surfaced via `error` above, rendered from the mutation state.
    }
  }

  return (
    <>
      <PageHeader
        title="New branch"
        description="A branch code is allocated automatically unless you set one."
      />

      <Card className="max-w-2xl">
        <form onSubmit={handleSubmit} className="space-y-5">
          {error && !error.isValidation ? (
            <Alert tone="error" reference={error.correlationId}>
              {error.message}
            </Alert>
          ) : null}

          <Field
            label="Name"
            required
            value={form.name}
            onChange={(event) => set('name', event.target.value)}
            error={error?.fieldError('name')}
          />

          <Field
            label="Address"
            value={form.address}
            onChange={(event) => set('address', event.target.value)}
            error={error?.fieldError('address')}
          />

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Field
              label="City"
              value={form.city}
              onChange={(event) => set('city', event.target.value)}
              error={error?.fieldError('city')}
            />
            <Field
              label="State"
              value={form.state}
              onChange={(event) => set('state', event.target.value)}
              error={error?.fieldError('state')}
            />
            <Field
              label="Country"
              value={form.country}
              onChange={(event) => set('country', event.target.value)}
              error={error?.fieldError('country')}
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
              label="Email"
              type="email"
              value={form.email}
              onChange={(event) => set('email', event.target.value)}
              error={error?.fieldError('email')}
            />
          </div>

          <div className="flex gap-3">
            <Button type="submit" loading={createBranch.isPending}>
              Create branch
            </Button>
          </div>
        </form>
      </Card>
    </>
  );
}
