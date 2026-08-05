'use client';

import { ApiError } from '@naipay/api-client';
import Link from 'next/link';
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
import { ActionButton } from '@/components/ui/workflow-action';
import { ReauthPrompt } from '@/components/auth/reauth-prompt';
import { useBankAccountOptions } from '@/lib/bank-accounts/use-bank-accounts';
import { formatAmountString, formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { useProtectedAction } from '@/lib/auth/use-reauthenticate';
import { useApproveLoan, useDisburseLoan, useLoan, useWriteOffLoan } from '@/lib/loans/use-loans';
import type { LoanStatusKey } from '@/lib/loans/types';

const statusTone: Record<LoanStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  pending_approval: 'warning',
  pending_disbursement: 'info',
  disbursed: 'success',
  written_off: 'danger',
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

function DisburseAction({ loanId }: { loanId: number }) {
  const [open, setOpen] = useState(false);
  const [bankAccountId, setBankAccountId] = useState('');
  const [date, setDate] = useState('');
  const { data: bankAccounts } = useBankAccountOptions();
  const disburseLoan = useDisburseLoan(loanId);

  const protectedDisburse = useProtectedAction((input: { bank_account_id: number; disbursement_date?: string }) =>
    disburseLoan.mutateAsync(input),
  );

  if (protectedDisburse.needsReauthentication) {
    return (
      <ReauthPrompt
        pending={protectedDisburse.reauthenticating}
        error={protectedDisburse.reauthenticationError}
        onCancel={protectedDisburse.cancelReauthentication}
        onConfirm={(password, code) => protectedDisburse.confirmReauthentication(password, code)}
      />
    );
  }

  if (!open) {
    return (
      <Button type="button" onClick={() => setOpen(true)}>
        Disburse
      </Button>
    );
  }

  return (
    <div className="w-full max-w-md space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4">
      {protectedDisburse.actionError ? (
        <Alert tone="error" reference={protectedDisburse.actionError.correlationId}>
          {protectedDisburse.actionError.message}
        </Alert>
      ) : null}

      <SelectField
        label="Disbursement account"
        placeholder="Select an account"
        options={(bankAccounts ?? []).map((account) => ({ value: String(account.value), label: account.label }))}
        value={bankAccountId}
        onChange={(event) => setBankAccountId(event.target.value)}
      />
      <Field label="Disbursement date" type="date" value={date} onChange={(event) => setDate(event.target.value)} />

      <div className="flex gap-2">
        <Button
          type="button"
          loading={protectedDisburse.running}
          disabled={!bankAccountId}
          onClick={() =>
            protectedDisburse.run({
              bank_account_id: Number(bankAccountId),
              disbursement_date: date || undefined,
            })
          }
        >
          Confirm disbursement
        </Button>
        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

function WriteOffAction({ loanId }: { loanId: number }) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const writeOffLoan = useWriteOffLoan(loanId);

  const protectedWriteOff = useProtectedAction((input: { reason: string }) => writeOffLoan.mutateAsync(input));

  if (protectedWriteOff.needsReauthentication) {
    return (
      <ReauthPrompt
        pending={protectedWriteOff.reauthenticating}
        error={protectedWriteOff.reauthenticationError}
        onCancel={protectedWriteOff.cancelReauthentication}
        onConfirm={(password, code) => protectedWriteOff.confirmReauthentication(password, code)}
      />
    );
  }

  if (!open) {
    return (
      <Button type="button" variant="destructive" onClick={() => setOpen(true)}>
        Write off
      </Button>
    );
  }

  return (
    <div className="w-full max-w-md space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4">
      {protectedWriteOff.actionError ? (
        <Alert tone="error" reference={protectedWriteOff.actionError.correlationId}>
          {protectedWriteOff.actionError.message}
        </Alert>
      ) : null}

      <TextareaField
        label="Reason for writing off this loan"
        value={reason}
        onChange={(event) => setReason(event.target.value)}
        autoFocus
        hint="At least 10 characters. Recorded in the audit log."
      />

      <div className="flex gap-2">
        <Button
          type="button"
          variant="destructive"
          loading={protectedWriteOff.running}
          onClick={() => protectedWriteOff.run({ reason })}
        >
          Confirm write-off
        </Button>
        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

export default function LoanDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: loan, isLoading, error } = useLoan(id);
  const approveLoan = useApproveLoan(id);
  const approveError = approveLoan.error instanceof ApiError ? approveLoan.error : null;

  return (
    <QueryState isLoading={isLoading} error={error}>
      {loan ? (
        <div className="space-y-6">
          <PageHeader
            title={loan.loan_reference}
            description={loan.merchant?.full_name}
            actions={<Badge tone={statusTone[loan.status]}>{loan.status_label}</Badge>}
          />

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Workflow</h2>
            <div className="flex flex-wrap items-start gap-3">
              {loan.allowed_transitions.includes('pending_disbursement') ? (
                <ActionButton label="Approve" onConfirm={() => approveLoan.mutateAsync()} />
              ) : null}
              {loan.allowed_transitions.includes('disbursed') ? <DisburseAction loanId={loan.id} /> : null}
              {loan.allowed_transitions.includes('written_off') ? <WriteOffAction loanId={loan.id} /> : null}
              {loan.allowed_transitions.length === 0 ? (
                <p className="text-sm text-slate-500">No further transitions — this loan is in a terminal state.</p>
              ) : null}
            </div>
            {approveError ? (
              <Alert tone="error" reference={approveError.correlationId}>
                {approveError.message}
              </Alert>
            ) : null}
          </Card>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Card>
              <h2 className="mb-4 text-sm font-semibold text-slate-900">Terms</h2>
              <div className="grid grid-cols-2 gap-4">
                <Detail label="Principal" value={formatMoney(loan.terms.principal_amount)} />
                <Detail label="Interest rate" value={`${loan.terms.interest_rate}%`} />
                <Detail label="Tenor" value={loan.terms.tenor} />
                <Detail label="Repayment frequency" value={loan.terms.repayment_frequency} />
                {loan.totals ? (
                  <>
                    <Detail label="Total payable" value={formatMoney(loan.totals.total_payable)} />
                    <Detail label="Total interest" value={formatMoney(loan.totals.total_interest)} />
                  </>
                ) : null}
                {loan.outstanding ? (
                  <Detail label="Outstanding principal" value={formatMoney(loan.outstanding.principal)} />
                ) : null}
              </div>
            </Card>

            <Card>
              <h2 className="mb-4 text-sm font-semibold text-slate-900">References</h2>
              <div className="grid grid-cols-2 gap-4">
                <Detail
                  label="Merchant"
                  value={
                    loan.merchant ? (
                      <Link href={`/merchants/${loan.merchant.id}`} className="text-brand-700 hover:underline">
                        {loan.merchant.full_name}
                      </Link>
                    ) : null
                  }
                />
                <Detail
                  label="Business"
                  value={
                    loan.business ? (
                      <Link href={`/businesses/${loan.business.id}`} className="text-brand-700 hover:underline">
                        {loan.business.business_name}
                      </Link>
                    ) : null
                  }
                />
                <Detail
                  label="Loan product"
                  value={
                    loan.loan_product ? (
                      <Link href={`/loan-products/${loan.loan_product.id}`} className="text-brand-700 hover:underline">
                        {loan.loan_product.name}
                      </Link>
                    ) : null
                  }
                />
                <Detail
                  label="Application"
                  value={
                    loan.loan_application_id ? (
                      <Link
                        href={`/loan-applications/${loan.loan_application_id}`}
                        className="text-brand-700 hover:underline"
                      >
                        View application
                      </Link>
                    ) : null
                  }
                />
              </div>
            </Card>

            {loan.disbursement ? (
              <Card>
                <h2 className="mb-4 text-sm font-semibold text-slate-900">Disbursement</h2>
                <div className="grid grid-cols-2 gap-4">
                  <Detail label="Account" value={loan.disbursement.bank_account} />
                  <Detail label="Date" value={formatDate(loan.disbursement.date)} />
                  <Detail label="First repayment" value={formatDate(loan.disbursement.first_repayment_date)} />
                  <Detail label="Maturity" value={formatDate(loan.disbursement.maturity_date)} />
                  <Detail label="Disbursed by" value={loan.disbursement.disbursed_by?.full_name} />
                </div>
              </Card>
            ) : null}

            {loan.write_off ? (
              <Card>
                <h2 className="mb-4 text-sm font-semibold text-slate-900">Write-off</h2>
                <div className="grid grid-cols-2 gap-4">
                  <Detail label="Written off by" value={loan.write_off.written_off_by?.full_name} />
                  <Detail label="Written off at" value={formatDateTime(loan.write_off.written_off_at)} />
                </div>
                <p className="mt-2 text-sm text-slate-700">{loan.write_off.reason}</p>
              </Card>
            ) : null}
          </div>

          {loan.schedule.length > 0 ? (
            <Card className="overflow-x-auto p-0">
              <h2 className="px-4 pt-4 text-sm font-semibold text-slate-900">Repayment schedule</h2>
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                    <th className="px-4 py-3">#</th>
                    <th className="px-4 py-3">Due date</th>
                    <th className="px-4 py-3 text-right">Principal</th>
                    <th className="px-4 py-3 text-right">Interest</th>
                    <th className="px-4 py-3 text-right">Total due</th>
                    <th className="px-4 py-3">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {loan.schedule.map((entry) => (
                    <tr key={entry.id} className="border-b border-slate-100 last:border-0">
                      <td className="px-4 py-3 text-slate-500">{entry.installment_number}</td>
                      <td className="px-4 py-3 text-slate-700">{entry.due_date}</td>
                      <td className="numeric px-4 py-3 text-right text-slate-700">
                        {formatAmountString(entry.principal_due)}
                      </td>
                      <td className="numeric px-4 py-3 text-right text-slate-700">
                        {formatAmountString(entry.interest_due)}
                      </td>
                      <td className="numeric px-4 py-3 text-right font-medium text-slate-900">
                        {formatAmountString(entry.total_due)}
                      </td>
                      <td className="px-4 py-3">
                        <Badge tone="neutral">{entry.status_label}</Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          ) : null}

          <p className="text-xs text-slate-400">Last updated {formatDateTime(loan.updated_at)}</p>
        </div>
      ) : null}
    </QueryState>
  );
}
