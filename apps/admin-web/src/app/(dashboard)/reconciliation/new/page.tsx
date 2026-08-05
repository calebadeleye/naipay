'use client';

import { ApiError } from '@naipay/api-client';
import { useRouter } from 'next/navigation';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select';
import { useBankAccountOptions } from '@/lib/bank-accounts/use-bank-accounts';
import { useOpenReconciliation } from '@/lib/reconciliation/use-reconciliation';
import type { OpenReconciliationInput } from '@/lib/reconciliation/types';

const emptyForm: OpenReconciliationInput = {
  bank_account_id: 0,
  period_start: '',
  period_end: '',
  statement_opening_balance: '',
  statement_closing_balance: '',
  notes: '',
};

export default function NewReconciliationPage() {
  const router = useRouter();
  const [form, setForm] = useState<OpenReconciliationInput>(emptyForm);
  const { data: bankAccounts } = useBankAccountOptions();
  const openReconciliation = useOpenReconciliation();
  const error = openReconciliation.error instanceof ApiError ? openReconciliation.error : null;

  function set<K extends keyof OpenReconciliationInput>(key: K, value: OpenReconciliationInput[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();

    try {
      const reconciliation = await openReconciliation.mutateAsync(form);
      router.replace(`/reconciliation/${reconciliation.id}`);
    } catch {
      // Surfaced via `error` above, rendered from the mutation state.
    }
  }

  return (
    <>
      <PageHeader
        title="Open reconciliation"
        description="Starts a reconciliation exercise for one bank account over one statement period."
      />

      <Card className="max-w-2xl">
        <form onSubmit={handleSubmit} className="space-y-5">
          {error && !error.isValidation ? (
            <Alert tone="error" reference={error.correlationId}>
              {error.message}
            </Alert>
          ) : null}

          <SelectField
            label="Bank account"
            placeholder="Select an account"
            options={(bankAccounts ?? []).map((account) => ({ value: String(account.value), label: account.label }))}
            value={form.bank_account_id ? String(form.bank_account_id) : ''}
            onChange={(event) => set('bank_account_id', Number(event.target.value))}
            error={error?.fieldError('bank_account_id')}
          />

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="Period start"
              type="date"
              required
              value={form.period_start}
              onChange={(event) => set('period_start', event.target.value)}
              error={error?.fieldError('period_start')}
            />
            <Field
              label="Period end"
              type="date"
              required
              value={form.period_end}
              onChange={(event) => set('period_end', event.target.value)}
              error={error?.fieldError('period_end')}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="Statement opening balance"
              placeholder="0.00"
              value={form.statement_opening_balance}
              onChange={(event) => set('statement_opening_balance', event.target.value)}
              error={error?.fieldError('statement_opening_balance')}
            />
            <Field
              label="Statement closing balance"
              placeholder="0.00"
              value={form.statement_closing_balance}
              onChange={(event) => set('statement_closing_balance', event.target.value)}
              error={error?.fieldError('statement_closing_balance')}
            />
          </div>

          <Field
            label="Notes"
            value={form.notes}
            onChange={(event) => set('notes', event.target.value)}
            error={error?.fieldError('notes')}
          />

          <div className="flex gap-3">
            <Button type="submit" loading={openReconciliation.isPending}>
              Open reconciliation
            </Button>
          </div>
        </form>
      </Card>
    </>
  );
}
