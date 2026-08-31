'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useState } from 'react';

import { Alert } from '@/components/ui/field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { TextareaField } from '@/components/ui/textarea';
import { ActionButton, ReasonActionButton } from '@/components/ui/workflow-action';
import { ReauthPrompt } from '@/components/auth/reauth-prompt';
import { formatAmountString, formatDateTime, formatMoney, merchantLabel } from '@/lib/format';
import { useProtectedAction } from '@/lib/auth/use-reauthenticate';
import {
  useApproveRepayment,
  useRejectRepayment,
  useRepayment,
  useReverseRepayment,
  useVerifyRepayment,
} from '@/lib/repayments/use-repayments';
import type { RepaymentStatusKey } from '@/lib/repayments/types';

const statusTone: Record<RepaymentStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  recorded: 'neutral',
  verified: 'info',
  approved: 'success',
  rejected: 'danger',
  reversed: 'danger',
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

function ReverseAction({ repaymentId }: { repaymentId: number }) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const reverseRepayment = useReverseRepayment(repaymentId);

  const protectedReverse = useProtectedAction((input: { reason: string }) => reverseRepayment.mutateAsync(input));

  if (protectedReverse.needsReauthentication) {
    return (
      <ReauthPrompt
        pending={protectedReverse.reauthenticating}
        error={protectedReverse.reauthenticationError}
        onCancel={protectedReverse.cancelReauthentication}
        onConfirm={(password, code) => protectedReverse.confirmReauthentication(password, code)}
      />
    );
  }

  if (!open) {
    return (
      <Button type="button" variant="destructive" onClick={() => setOpen(true)}>
        Reverse
      </Button>
    );
  }

  return (
    <div className="w-full max-w-md space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4">
      {protectedReverse.actionError ? (
        <Alert tone="error" reference={protectedReverse.actionError.correlationId}>
          {protectedReverse.actionError.message}
        </Alert>
      ) : null}

      <TextareaField
        label="Reason for reversal"
        value={reason}
        onChange={(event) => setReason(event.target.value)}
        autoFocus
        hint="At least 10 characters. Recorded in the audit log."
      />

      <div className="flex gap-2">
        <Button
          type="button"
          variant="destructive"
          loading={protectedReverse.running}
          onClick={() => protectedReverse.run({ reason })}
        >
          Confirm reversal
        </Button>
        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

export default function RepaymentDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: repayment, isLoading, error } = useRepayment(id);

  const verify = useVerifyRepayment(id);
  const reject = useRejectRepayment(id);
  const approve = useApproveRepayment(id);

  return (
    <QueryState isLoading={isLoading} error={error}>
      {repayment ? (
        <div className="space-y-6">
          <PageHeader
            title={merchantLabel(repayment.merchant)}
            description={[repayment.merchant?.full_name, formatMoney(repayment.amount)].filter(Boolean).join(' · ')}
            actions={<Badge tone={statusTone[repayment.status]}>{repayment.status_label}</Badge>}
          />

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Workflow</h2>
            <div className="flex flex-wrap items-start gap-3">
              {repayment.allowed_transitions.includes('verified') ? (
                <ActionButton label="Verify" onConfirm={() => verify.mutateAsync(undefined)} />
              ) : null}
              {repayment.allowed_transitions.includes('approved') ? (
                <ActionButton label="Approve" onConfirm={() => approve.mutateAsync(undefined)} />
              ) : null}
              {repayment.allowed_transitions.includes('rejected') ? (
                <ReasonActionButton
                  label="Reject"
                  reasonLabel="Reason for rejection"
                  onConfirm={(reason) => reject.mutateAsync({ reason })}
                />
              ) : null}
              {repayment.allowed_transitions.includes('reversed') ? (
                <ReverseAction repaymentId={repayment.id} />
              ) : null}
              {repayment.allowed_transitions.length === 0 ? (
                <p className="text-sm text-slate-500">No further transitions — this repayment is in a terminal state.</p>
              ) : null}
            </div>
            {repayment.rejection ? (
              <p className="mt-4 text-sm text-danger">Rejection reason: {repayment.rejection.reason}</p>
            ) : null}
            {repayment.reversal ? (
              <p className="mt-4 text-sm text-danger">Reversal reason: {repayment.reversal.reason}</p>
            ) : null}
          </Card>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Card>
              <h2 className="mb-4 text-sm font-semibold text-slate-900">Payment</h2>
              <div className="grid grid-cols-2 gap-4">
                <Detail label="Amount" value={formatMoney(repayment.amount)} />
                <Detail label="Payment date" value={repayment.payment_date} />
                <Detail label="Method" value={repayment.payment_method_label} />
                <Detail label="Receiving account" value={repayment.receiving_bank_account} />
                <Detail label="Sender account" value={repayment.sender_account_name} />
                <Detail label="Sender account number" value={repayment.sender_account_number} />
                <Detail label="Sender bank" value={repayment.sender_bank_name} />
                <Detail label="Bank reference" value={repayment.bank_reference} />
                <Detail
                  label="Loan"
                  value={
                    repayment.loan ? (
                      <Link href={`/loans/${repayment.loan.id}`} className="text-brand-700 hover:underline">
                        View loan
                      </Link>
                    ) : null
                  }
                />
              </div>
              {repayment.notes ? (
                <>
                  <h3 className="mt-4 text-xs font-medium tracking-wide text-slate-500 uppercase">Notes</h3>
                  <p className="mt-1 text-sm text-slate-700">{repayment.notes}</p>
                </>
              ) : null}
            </Card>

            <Card>
              <h2 className="mb-4 text-sm font-semibold text-slate-900">History</h2>
              <div className="space-y-3">
                <Detail label="Recorded by" value={repayment.recorded_by?.full_name} />
                {repayment.verification ? (
                  <Detail
                    label="Verified by"
                    value={`${repayment.verification.verified_by?.full_name} · ${formatDateTime(repayment.verification.verified_at)}`}
                  />
                ) : null}
                {repayment.approved_by ? <Detail label="Approved by" value={repayment.approved_by.full_name} /> : null}
              </div>
            </Card>
          </div>

          {repayment.allocation ? (
            <Card>
              <h2 className="mb-4 text-sm font-semibold text-slate-900">Allocation</h2>
              <div className="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-5">
                <Detail label="Principal" value={formatMoney(repayment.allocation.principal)} />
                <Detail label="Interest" value={formatMoney(repayment.allocation.interest)} />
                <Detail label="Fee" value={formatMoney(repayment.allocation.fee)} />
                <Detail label="Excess" value={formatMoney(repayment.allocation.excess)} />
                <Detail label="Unallocated" value={formatMoney(repayment.allocation.unallocated)} />
              </div>

              {repayment.allocation.entries.length > 0 ? (
                <div className="overflow-x-auto rounded-md border border-slate-200">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                        <th className="px-3 py-2">Instalment</th>
                        <th className="px-3 py-2 text-right">Principal</th>
                        <th className="px-3 py-2 text-right">Interest</th>
                        <th className="px-3 py-2 text-right">Fee</th>
                      </tr>
                    </thead>
                    <tbody>
                      {repayment.allocation.entries.map((entry) => (
                        <tr key={entry.loan_schedule_entry_id} className="border-b border-slate-100 last:border-0">
                          <td className="px-3 py-2 text-slate-700">{entry.installment_number}</td>
                          <td className="numeric px-3 py-2 text-right text-slate-700">
                            {formatAmountString(entry.principal_amount)}
                          </td>
                          <td className="numeric px-3 py-2 text-right text-slate-700">
                            {formatAmountString(entry.interest_amount)}
                          </td>
                          <td className="numeric px-3 py-2 text-right text-slate-700">
                            {formatAmountString(entry.fee_amount)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : null}
            </Card>
          ) : null}

          <p className="text-xs text-slate-400">Last updated {formatDateTime(repayment.updated_at)}</p>
        </div>
      ) : null}
    </QueryState>
  );
}
