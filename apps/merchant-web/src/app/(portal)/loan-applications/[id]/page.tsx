'use client';

import { ApiError } from '@naipay/api-client';
import { useParams } from 'next/navigation';
import { useState } from 'react';

import { Alert } from '@/components/ui/field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { TextareaField } from '@/components/ui/textarea';
import { useLoanApplication, useWithdrawLoanApplication } from '@/lib/loan-applications/use-loan-applications';
import type { LoanApplicationStatusKey } from '@/lib/loan-applications/types';
import { formatDate, formatMoney } from '@/lib/format';

const statusTone: Record<LoanApplicationStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  draft: 'neutral',
  submitted: 'info',
  under_assessment: 'info',
  recommended: 'info',
  approved: 'success',
  rejected: 'danger',
  withdrawn: 'neutral',
  expired: 'neutral',
};

const WITHDRAWABLE: LoanApplicationStatusKey[] = ['draft', 'submitted', 'under_assessment', 'recommended'];

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

function WithdrawAction({ applicationId }: { applicationId: number }) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const withdraw = useWithdrawLoanApplication(applicationId);
  const error = withdraw.error as ApiError | null;

  if (!open) {
    return (
      <Button type="button" variant="secondary" onClick={() => setOpen(true)}>
        Withdraw application
      </Button>
    );
  }

  return (
    <div className="w-full max-w-md space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4">
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}
      <TextareaField
        label="Reason for withdrawing"
        value={reason}
        onChange={(event) => setReason(event.target.value)}
        error={error?.fieldError('reason')}
      />
      <div className="flex gap-2">
        <Button
          type="button"
          variant="destructive"
          loading={withdraw.isPending}
          disabled={reason.length < 10}
          onClick={() => withdraw.mutateAsync({ reason }).then(() => setOpen(false))}
        >
          Confirm withdrawal
        </Button>
        <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

export default function LoanApplicationDetailPage() {
  const params = useParams<{ id: string }>();
  const { data: application, isLoading, error } = useLoanApplication(params.id);

  return (
    <QueryState isLoading={isLoading} error={error}>
      {application ? (
        <div className="space-y-6">
          <PageHeader
            title={application.application_number}
            description={application.loan_product?.name ?? undefined}
            actions={<Badge tone={statusTone[application.status]}>{application.status_label}</Badge>}
          />

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Details</h2>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
              <Detail label="Requested amount" value={formatMoney(application.requested.amount)} />
              <Detail label="Requested tenor" value={application.requested.tenor} />
              <Detail label="Business" value={application.business?.business_name} />
              {application.approved ? (
                <>
                  <Detail label="Approved amount" value={formatMoney(application.approved.amount)} />
                  <Detail label="Approved tenor" value={application.approved.tenor} />
                  <Detail label="Interest rate" value={`${application.approved.interest_rate}%`} />
                </>
              ) : null}
              <Detail label="Submitted" value={formatDate(application.submitted_at)} />
            </div>

            {application.purpose ? (
              <div className="mt-4">
                <Detail label="Purpose" value={application.purpose} />
              </div>
            ) : null}

            {application.decision_reason ? (
              <p className="mt-4 text-sm text-danger">Reason: {application.decision_reason}</p>
            ) : null}
            {application.withdrawal_reason ? (
              <p className="mt-4 text-sm text-slate-600">Withdrawn: {application.withdrawal_reason}</p>
            ) : null}
          </Card>

          {application.loan_product?.requires_guarantor ? (
            <Card>
              <h2 className="mb-2 text-sm font-semibold text-slate-900">Guarantors</h2>
              <p className="text-sm text-slate-600">
                This product requires at least {application.loan_product.minimum_guarantors} guarantor(s).
                {application.guarantors_satisfied
                  ? ' Requirement met.'
                  : ' Contact Every Merchant to arrange this.'}
              </p>
            </Card>
          ) : null}

          {WITHDRAWABLE.includes(application.status) ? (
            <Card>
              <h2 className="mb-4 text-sm font-semibold text-slate-900">Actions</h2>
              <WithdrawAction applicationId={application.id} />
            </Card>
          ) : null}
        </div>
      ) : null}
    </QueryState>
  );
}
