'use client';

import { ApiError } from '@naipay/api-client';
import Link from 'next/link';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { RequirePermission } from '@/components/auth/require-permission';
import { useCreateInvestor } from '@/lib/investors/use-investors';
import type { Investor, InvestorFormInput } from '@/lib/investors/types';

const emptyForm: InvestorFormInput = {
  name: '',
  email: '',
  phone: '',
};

export default function NewInvestorPage() {
  return (
    <RequirePermission permission="investors.create">
      <NewInvestorForm />
    </RequirePermission>
  );
}

function NewInvestorForm() {
  const [form, setForm] = useState<InvestorFormInput>(emptyForm);
  const createInvestor = useCreateInvestor();
  const error = createInvestor.error instanceof ApiError ? createInvestor.error : null;

  const [created, setCreated] = useState<{ investor: Investor; temporary_password: string } | null>(null);

  function set<K extends keyof InvestorFormInput>(key: K, value: InvestorFormInput[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();

    try {
      const result = await createInvestor.mutateAsync(form);
      setCreated(result);
    } catch {
      // Surfaced via `error` above, rendered from the mutation state.
    }
  }

  if (created) {
    return (
      <>
        <PageHeader title="Investor created" description={created.investor.investor_number} />

        <Card className="max-w-2xl space-y-4">
          <Alert tone="success">
            {created.investor.name} has been created. Give them this temporary password through a secure channel —
            it is shown once.
          </Alert>

          <div className="rounded-md border border-slate-300 bg-slate-50 px-4 py-3">
            <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">Temporary password</p>
            <p className="numeric mt-1 text-lg font-semibold text-slate-900">{created.temporary_password}</p>
          </div>

          <Link href={`/investors/${created.investor.id}`} className="text-sm text-brand-700 hover:underline">
            View investor
          </Link>
        </Card>
      </>
    );
  }

  return (
    <>
      <PageHeader
        title="New investor"
        description="Creates the account with a temporary password, shown once."
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
              label="Phone"
              value={form.phone}
              onChange={(event) => set('phone', event.target.value)}
              error={error?.fieldError('phone')}
            />
          </div>

          <div className="flex gap-3">
            <Button type="submit" loading={createInvestor.isPending}>
              Create investor
            </Button>
          </div>
        </form>
      </Card>
    </>
  );
}
