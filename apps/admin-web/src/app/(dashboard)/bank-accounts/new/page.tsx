'use client';

import { ApiError } from '@naipay/api-client';
import { useRouter } from 'next/navigation';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select';
import { useCreateBankAccount } from '@/lib/bank-accounts/use-bank-accounts';
import type { BankAccountFormInput } from '@/lib/bank-accounts/types';

const purposeOptions = [
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
  account_purpose: 'loan_repayment_collection',
};

export default function NewBankAccountPage() {
  const router = useRouter();
  const [form, setForm] = useState<BankAccountFormInput>(emptyForm);
  const createAccount = useCreateBankAccount();
  const error = createAccount.error instanceof ApiError ? createAccount.error : null;

  function set<K extends keyof BankAccountFormInput>(key: K, value: BankAccountFormInput[K]) {
    setForm((current) => ({ ...current, [key]: value }));
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

          <SelectField
            label="Purpose"
            options={purposeOptions}
            value={form.account_purpose}
            onChange={(event) => set('account_purpose', event.target.value)}
            error={error?.fieldError('account_purpose')}
          />

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
