'use client';

import { ApiError } from '@naipay/api-client';
import { useRouter } from 'next/navigation';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { RequirePermission } from '@/components/auth/require-permission';
import { useCreateBankAccount } from '@/lib/bank-accounts/use-bank-accounts';
import type { BankAccountFormInput, BankAccountPurposeKey } from '@/lib/bank-accounts/types';

const purposeOptions: { value: BankAccountPurposeKey; label: string }[] = [
  { value: 'loan_repayment_collection', label: 'Loan Repayment Collection' },
  { value: 'loan_disbursement', label: 'Loan Disbursement' },
  { value: 'operating_account', label: 'Operating Account' },
  { value: 'settlement_account', label: 'Settlement Account' },
  { value: 'suspense_account', label: 'Suspense Account' },
  { value: 'other', label: 'Other' },
];

const emptyForm: BankAccountFormInput = {
  bank_name: '',
  bank_code: '',
  account_name: '',
  account_number: '',
  branch_name: '',
  currency: 'NGN',
  purposes: ['loan_repayment_collection'],
};

export default function NewBankAccountPage() {
  return (
    <RequirePermission permission="bank_accounts.manage">
      <NewBankAccountForm />
    </RequirePermission>
  );
}

function NewBankAccountForm() {
  const router = useRouter();
  const [form, setForm] = useState<BankAccountFormInput>(emptyForm);
  const createAccount = useCreateBankAccount();
  const error = createAccount.error instanceof ApiError ? createAccount.error : null;

  function set<K extends keyof BankAccountFormInput>(key: K, value: BankAccountFormInput[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  function togglePurpose(purpose: BankAccountPurposeKey, checked: boolean) {
    setForm((current) => {
      const next = checked
        ? [...current.purposes, purpose]
        : current.purposes.filter((value) => value !== purpose);

      // Preserve the canonical option order regardless of click order.
      return {
        ...current,
        purposes: purposeOptions
          .map((option) => option.value)
          .filter((value) => next.includes(value)),
      };
    });
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();

    try {
      const account = await createAccount.mutateAsync(form);
      router.replace(`/bank-accounts/${account.id}`);
    } catch {
      // Surfaced via `error` above, rendered from the mutation state.
    }
  }

  return (
    <>
      <PageHeader
        title="Add bank account"
        description="Proposes a new designated account. A different officer must approve it before it can be used."
      />

      <Card className="max-w-2xl">
        <form onSubmit={handleSubmit} className="space-y-5">
          {error && !error.isValidation ? (
            <Alert tone="error" reference={error.correlationId}>
              {error.message}
            </Alert>
          ) : null}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="Bank name"
              required
              value={form.bank_name}
              onChange={(event) => set('bank_name', event.target.value)}
              error={error?.fieldError('bank_name')}
            />
            <Field
              label="Bank code"
              value={form.bank_code}
              onChange={(event) => set('bank_code', event.target.value)}
              error={error?.fieldError('bank_code')}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="Account name"
              required
              value={form.account_name}
              onChange={(event) => set('account_name', event.target.value)}
              error={error?.fieldError('account_name')}
            />
            <Field
              label="Account number"
              required
              placeholder="10 digits"
              value={form.account_number}
              onChange={(event) => set('account_number', event.target.value)}
              error={error?.fieldError('account_number')}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="Branch name"
              value={form.branch_name}
              onChange={(event) => set('branch_name', event.target.value)}
              error={error?.fieldError('branch_name')}
            />
            <Field
              label="Currency"
              value={form.currency}
              onChange={(event) => set('currency', event.target.value)}
              error={error?.fieldError('currency')}
            />
          </div>

          <fieldset className="space-y-2">
            <legend className="block text-sm font-medium text-slate-800">Purposes</legend>
            <p className="text-xs text-slate-500">
              An account can serve more than one — commonly both repayment collection and
              disbursement.
            </p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {purposeOptions.map((option) => (
                <label
                  key={option.value}
                  className="flex items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-800"
                >
                  <input
                    type="checkbox"
                    className="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    checked={form.purposes.includes(option.value)}
                    onChange={(event) => togglePurpose(option.value, event.target.checked)}
                  />
                  {option.label}
                </label>
              ))}
            </div>
            {error?.fieldError('purposes') ? (
              <p className="text-xs text-danger">{error.fieldError('purposes')}</p>
            ) : null}
          </fieldset>

          <div className="flex gap-3">
            <Button type="submit" loading={createAccount.isPending}>
              Add account
            </Button>
          </div>
        </form>
      </Card>
    </>
  );
}
