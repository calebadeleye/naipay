'use client';

import { ApiError } from '@naipay/api-client';
import { useParams, useRouter } from 'next/navigation';
import { useState } from 'react';

import { RequirePermission } from '@/components/auth/require-permission';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Alert, Field } from '@/components/ui/field';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { useBankAccount, useUpdateBankAccount } from '@/lib/bank-accounts/use-bank-accounts';
import type { BankAccountPurposeKey } from '@/lib/bank-accounts/types';

const purposeOptions: { value: BankAccountPurposeKey; label: string }[] = [
  { value: 'loan_repayment_collection', label: 'Loan Repayment Collection' },
  { value: 'loan_disbursement', label: 'Loan Disbursement' },
  { value: 'operating_account', label: 'Operating Account' },
  { value: 'settlement_account', label: 'Settlement Account' },
  { value: 'suspense_account', label: 'Suspense Account' },
  { value: 'other', label: 'Other' },
];

interface FormState {
  bank_name: string;
  bank_code: string;
  account_name: string;
  account_number: string;
  branch_name: string;
  currency: string;
  purposes: BankAccountPurposeKey[];
}

export default function EditBankAccountPage() {
  return (
    <RequirePermission permission="bank_accounts.manage">
      <EditBankAccountForm />
    </RequirePermission>
  );
}

function EditBankAccountForm() {
  const params = useParams<{ id: string }>();
  const id = params.id;
  const router = useRouter();

  const { data: account, isLoading, error } = useBankAccount(id);
  const update = useUpdateBankAccount(id);
  const apiError = update.error instanceof ApiError ? update.error : null;

  const [form, setForm] = useState<FormState | null>(null);

  // Seed the form from the account once it has loaded. `account` is stable
  // between renders (react-query), so this is the "adjust state during render"
  // pattern, not an effect.
  const [seededId, setSeededId] = useState<number | null>(null);
  if (account && seededId !== account.id) {
    setSeededId(account.id);
    setForm({
      bank_name: account.bank_name,
      bank_code: account.bank_code ?? '',
      account_name: account.account_name,
      account_number: account.account_number,
      branch_name: account.branch_name ?? '',
      currency: account.currency,
      purposes: account.purposes,
    });
  }

  function set<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((current) => (current ? { ...current, [key]: value } : current));
  }

  function togglePurpose(purpose: BankAccountPurposeKey, checked: boolean) {
    setForm((current) => {
      if (!current) return current;
      const next = checked
        ? [...current.purposes, purpose]
        : current.purposes.filter((value) => value !== purpose);
      return {
        ...current,
        purposes: purposeOptions.map((o) => o.value).filter((value) => next.includes(value)),
      };
    });
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    if (!form) return;
    try {
      await update.mutateAsync(form);
      router.replace(`/bank-accounts/${id}`);
    } catch {
      // Surfaced via `apiError`.
    }
  }

  return (
    <>
      <PageHeader title="Edit bank account" description="Changing the bank, account number, purposes or currency withdraws approval — a different officer must confirm it again." />

      <QueryState isLoading={isLoading} error={error}>
        {form ? (
          <Card className="max-w-2xl">
            <form onSubmit={handleSubmit} className="space-y-5">
              {apiError && !apiError.isValidation ? (
                <Alert tone="error" reference={apiError.correlationId}>
                  {apiError.message}
                </Alert>
              ) : null}

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field
                  label="Bank name"
                  required
                  value={form.bank_name}
                  onChange={(e) => set('bank_name', e.target.value)}
                  error={apiError?.fieldError('bank_name')}
                />
                <Field
                  label="Bank code"
                  value={form.bank_code}
                  onChange={(e) => set('bank_code', e.target.value)}
                  error={apiError?.fieldError('bank_code')}
                />
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field
                  label="Account name"
                  required
                  value={form.account_name}
                  onChange={(e) => set('account_name', e.target.value)}
                  error={apiError?.fieldError('account_name')}
                />
                <Field
                  label="Account number"
                  required
                  placeholder="10 digits"
                  value={form.account_number}
                  onChange={(e) => set('account_number', e.target.value)}
                  error={apiError?.fieldError('account_number')}
                />
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field
                  label="Branch name"
                  value={form.branch_name}
                  onChange={(e) => set('branch_name', e.target.value)}
                  error={apiError?.fieldError('branch_name')}
                />
                <Field
                  label="Currency"
                  value={form.currency}
                  onChange={(e) => set('currency', e.target.value)}
                  error={apiError?.fieldError('currency')}
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
                        onChange={(e) => togglePurpose(option.value, e.target.checked)}
                      />
                      {option.label}
                    </label>
                  ))}
                </div>
                {apiError?.fieldError('purposes') ? (
                  <p className="text-xs text-danger">{apiError.fieldError('purposes')}</p>
                ) : null}
              </fieldset>

              <div className="flex gap-3">
                <Button type="submit" loading={update.isPending}>
                  Save changes
                </Button>
                <Button type="button" variant="secondary" onClick={() => router.replace(`/bank-accounts/${id}`)}>
                  Cancel
                </Button>
              </div>
            </form>
          </Card>
        ) : null}
      </QueryState>
    </>
  );
}
