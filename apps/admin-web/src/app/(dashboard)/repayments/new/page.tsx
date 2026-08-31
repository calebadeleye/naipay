'use client';

import { ApiError } from '@naipay/api-client';
import { useRouter, useSearchParams } from 'next/navigation';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select';
import { RequirePermission } from '@/components/auth/require-permission';
import { merchantLabel } from '@/lib/format';
import { useBankAccountOptions } from '@/lib/bank-accounts/use-bank-accounts';
import { useLoan, useLoans } from '@/lib/loans/use-loans';
import { useCreateRepayment } from '@/lib/repayments/use-repayments';
import type { Loan } from '@/lib/loans/types';

const paymentMethodOptions = [
  { value: 'bank_transfer', label: 'Bank transfer' },
  { value: 'cash', label: 'Cash' },
  { value: 'pos', label: 'POS' },
  { value: 'other', label: 'Other' },
];

function LoanPicker({ selected, onSelect }: { selected: Loan | null; onSelect: (loan: Loan | null) => void }) {
  const [search, setSearch] = useState('');
  const { data, isFetching } = useLoans(
    search.length >= 2 ? { search, status: 'disbursed', per_page: 5 } : undefined,
  );

  if (selected) {
    return (
      <div className="space-y-1.5">
        <p className="block text-sm font-medium text-slate-800">Loan</p>
        <div className="flex items-center justify-between rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm">
          <div>
            <p className="numeric font-medium text-slate-900">{merchantLabel(selected.merchant)}</p>
            <p className="text-xs text-slate-500">
              {[selected.merchant?.full_name, selected.loan_product?.name].filter(Boolean).join(' · ')}
            </p>
          </div>
          <Button type="button" variant="ghost" size="sm" onClick={() => onSelect(null)}>
            Change
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-1.5">
      <Field
        label="Loan"
        placeholder="Search by account number or merchant name"
        value={search}
        onChange={(event) => setSearch(event.target.value)}
        hint="Type at least 2 characters to search disbursed loans."
      />
      {search.length >= 2 ? (
        <div className="max-h-56 overflow-y-auto rounded-md border border-slate-200">
          {isFetching ? (
            <p className="px-3 py-2 text-sm text-slate-500">Searching…</p>
          ) : data && data.items.length > 0 ? (
            data.items.map((loan) => (
              <button
                key={loan.id}
                type="button"
                onClick={() => onSelect(loan)}
                className="block w-full border-b border-slate-100 px-3 py-2 text-left text-sm last:border-0 hover:bg-slate-50"
              >
                <p className="numeric font-medium text-slate-900">{merchantLabel(loan.merchant)}</p>
                <p className="text-xs text-slate-500">
                  {[loan.merchant?.full_name, loan.loan_product?.name].filter(Boolean).join(' · ')}
                </p>
              </button>
            ))
          ) : (
            <p className="px-3 py-2 text-sm text-slate-500">No disbursed loans found.</p>
          )}
        </div>
      ) : null}
    </div>
  );
}

export default function NewRepaymentPage() {
  return (
    <RequirePermission permission="repayments.record">
      <NewRepaymentForm />
    </RequirePermission>
  );
}

function NewRepaymentForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const preselectLoanId = searchParams.get('loan');

  // Arrived from a merchant's profile with a specific loan in hand — that loan
  // is the selection until the officer explicitly changes it (`loanChoice`
  // then holds either their pick or `null` for "cleared").
  const { data: preselectedLoan } = useLoan(preselectLoanId ?? '');
  const [loanChoice, setLoanChoice] = useState<Loan | null | undefined>(undefined);
  const loan = loanChoice !== undefined ? loanChoice : (preselectedLoan ?? null);

  const [bankAccountId, setBankAccountId] = useState('');
  const [amount, setAmount] = useState('');
  const [paymentDate, setPaymentDate] = useState('');
  const [paymentMethod, setPaymentMethod] = useState('bank_transfer');
  const [senderAccountName, setSenderAccountName] = useState('');
  // `null` while untouched, so it can default to the loan's merchant account.
  const [senderAccountNumberInput, setSenderAccountNumberInput] = useState<string | null>(null);
  const senderAccountNumber = senderAccountNumberInput ?? loan?.merchant?.account_number ?? '';
  const [senderBankName, setSenderBankName] = useState('');
  const [bankReference, setBankReference] = useState('');
  const [notes, setNotes] = useState('');

  const { data: bankAccounts } = useBankAccountOptions();
  const createRepayment = useCreateRepayment();
  const error = createRepayment.error instanceof ApiError ? createRepayment.error : null;

  const isDuplicateWarning = Boolean(error?.isConflict && /duplicate/i.test(error.message));

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    if (!loan || !bankAccountId) return;

    try {
      const repayment = await createRepayment.mutateAsync({
        loan_id: loan.id,
        receiving_bank_account_id: Number(bankAccountId),
        amount,
        payment_date: paymentDate,
        payment_method: paymentMethod,
        sender_account_name: senderAccountName || undefined,
        sender_account_number: senderAccountNumber || undefined,
        sender_bank_name: senderBankName || undefined,
        bank_reference: bankReference || undefined,
        notes: notes || undefined,
        confirm_duplicate: isDuplicateWarning,
      });

      router.replace(`/repayments/${repayment.id}`);
    } catch {
      // Surfaced via `error` above, rendered from the mutation state.
    }
  }

  return (
    <>
      <PageHeader
        title="Record repayment"
        description="Reports what the bank shows. Verification and approval happen separately before anything is allocated."
      />

      <Card className="max-w-2xl">
        <form onSubmit={handleSubmit} className="space-y-5">
          {error && !error.isValidation && !isDuplicateWarning ? (
            <Alert tone="error" reference={error.correlationId}>
              {error.message}
            </Alert>
          ) : null}

          {isDuplicateWarning ? (
            <Alert tone="warning" reference={error?.correlationId}>
              {error?.message} If this is genuinely a new repayment, submit again to confirm.
            </Alert>
          ) : null}

          <LoanPicker selected={loan} onSelect={setLoanChoice} />

          <SelectField
            label="Receiving bank account"
            placeholder="Select an account"
            options={(bankAccounts ?? []).map((account) => ({ value: String(account.value), label: account.label }))}
            value={bankAccountId}
            onChange={(event) => setBankAccountId(event.target.value)}
            error={error?.fieldError('receiving_bank_account_id')}
          />

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="Amount"
              required
              placeholder="0.00"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              error={error?.fieldError('amount')}
            />
            <Field
              label="Payment date"
              type="date"
              required
              value={paymentDate}
              onChange={(event) => setPaymentDate(event.target.value)}
              error={error?.fieldError('payment_date')}
            />
          </div>

          <SelectField
            label="Payment method"
            options={paymentMethodOptions}
            value={paymentMethod}
            onChange={(event) => setPaymentMethod(event.target.value)}
            error={error?.fieldError('payment_method')}
          />

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="Sender account name"
              value={senderAccountName}
              onChange={(event) => setSenderAccountName(event.target.value)}
              error={error?.fieldError('sender_account_name')}
            />
            <Field
              label="Sender account number"
              value={senderAccountNumber}
              onChange={(event) => setSenderAccountNumberInput(event.target.value)}
              error={error?.fieldError('sender_account_number')}
              hint={loan ? 'Filled from the selected loan’s merchant account. Edit if it’s wrong.' : undefined}
            />
          </div>

          <Field
            label="Sender bank name"
            value={senderBankName}
            onChange={(event) => setSenderBankName(event.target.value)}
            error={error?.fieldError('sender_bank_name')}
          />

          <Field
            label="Bank reference"
            value={bankReference}
            onChange={(event) => setBankReference(event.target.value)}
            error={error?.fieldError('bank_reference')}
          />

          <Field
            label="Notes"
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
            error={error?.fieldError('notes')}
          />

          <div className="flex gap-3">
            <Button type="submit" loading={createRepayment.isPending} disabled={!loan || !bankAccountId}>
              {isDuplicateWarning ? 'Confirm and record anyway' : 'Record repayment'}
            </Button>
          </div>
        </form>
      </Card>
    </>
  );
}
