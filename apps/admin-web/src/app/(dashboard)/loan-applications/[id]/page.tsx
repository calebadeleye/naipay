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
import { ActionButton, ReasonActionButton } from '@/components/ui/workflow-action';
import { formatDateTime, formatMoney } from '@/lib/format';
import {
  useAddGuarantor,
  useApproveLoanApplication,
  useAssessLoanApplication,
  useLoanApplication,
  useRecommendLoanApplication,
  useRejectLoanApplication,
  useRemoveGuarantor,
  useReturnLoanApplicationToDraft,
  useSubmitLoanApplication,
  useWithdrawLoanApplication,
} from '@/lib/loan-applications/use-loan-applications';
import type { GuarantorFormInput, LoanApplicationStatusKey } from '@/lib/loan-applications/types';

const statusTone: Record<LoanApplicationStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  draft: 'neutral',
  submitted: 'info',
  under_assessment: 'warning',
  recommended: 'warning',
  approved: 'success',
  rejected: 'danger',
  withdrawn: 'neutral',
  expired: 'danger',
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

const emptyGuarantor: GuarantorFormInput = {
  full_name: '',
  phone: '',
  email: '',
  relationship: '',
  address: '',
  id_type: '',
  id_number: '',
  employer: '',
  occupation: '',
  monthly_income: '',
};

function GuarantorForm({ applicationId, onDone }: { applicationId: number; onDone: () => void }) {
  const [form, setForm] = useState<GuarantorFormInput>(emptyGuarantor);
  const addGuarantor = useAddGuarantor(applicationId);
  const error = addGuarantor.error instanceof ApiError ? addGuarantor.error : null;

  function set<K extends keyof GuarantorFormInput>(key: K, value: GuarantorFormInput[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    await addGuarantor.mutateAsync(form);
    onDone();
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4 rounded-md border border-slate-200 bg-slate-50 p-4">
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field
          label="Full name"
          required
          value={form.full_name}
          onChange={(event) => set('full_name', event.target.value)}
          error={error?.fieldError('full_name')}
        />
        <Field
          label="Phone"
          required
          placeholder="080..."
          value={form.phone}
          onChange={(event) => set('phone', event.target.value)}
          error={error?.fieldError('phone')}
        />
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field
          label="Relationship"
          required
          value={form.relationship}
          onChange={(event) => set('relationship', event.target.value)}
          error={error?.fieldError('relationship')}
        />
        <Field
          label="Email"
          type="email"
          value={form.email}
          onChange={(event) => set('email', event.target.value)}
          error={error?.fieldError('email')}
        />
      </div>
      <Field
        label="Address"
        value={form.address}
        onChange={(event) => set('address', event.target.value)}
        error={error?.fieldError('address')}
      />
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field
          label="ID type"
          value={form.id_type}
          onChange={(event) => set('id_type', event.target.value)}
          error={error?.fieldError('id_type')}
        />
        <Field
          label="ID number"
          value={form.id_number}
          onChange={(event) => set('id_number', event.target.value)}
          error={error?.fieldError('id_number')}
        />
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Field
          label="Employer"
          value={form.employer}
          onChange={(event) => set('employer', event.target.value)}
          error={error?.fieldError('employer')}
        />
        <Field
          label="Occupation"
          value={form.occupation}
          onChange={(event) => set('occupation', event.target.value)}
          error={error?.fieldError('occupation')}
        />
        <Field
          label="Monthly income"
          placeholder="0.00"
          value={form.monthly_income}
          onChange={(event) => set('monthly_income', event.target.value)}
          error={error?.fieldError('monthly_income')}
        />
      </div>

      <div className="flex gap-3">
        <Button type="submit" loading={addGuarantor.isPending}>
          Add guarantor
        </Button>
        <Button type="button" variant="secondary" onClick={onDone}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

export default function LoanApplicationDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: application, isLoading, error } = useLoanApplication(id);
  const [addingGuarantor, setAddingGuarantor] = useState(false);

  const submit = useSubmitLoanApplication(id);
  const assess = useAssessLoanApplication(id);
  const recommend = useRecommendLoanApplication(id);
  const approve = useApproveLoanApplication(id);
  const reject = useRejectLoanApplication(id);
  const returnToDraft = useReturnLoanApplicationToDraft(id);
  const withdraw = useWithdrawLoanApplication(id);
  const removeGuarantor = useRemoveGuarantor(id);

  return (
    <QueryState isLoading={isLoading} error={error}>
      {application ? (
        <div className="space-y-6">
          <PageHeader
            title={application.application_number}
            description={application.merchant?.full_name}
            actions={<Badge tone={statusTone[application.status]}>{application.status_label}</Badge>}
          />

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Workflow</h2>
            <div className="flex flex-wrap items-start gap-3">
              {application.allowed_transitions.includes('submitted') ? (
                <ActionButton label="Submit" onConfirm={() => submit.mutateAsync(undefined)} />
              ) : null}
              {application.allowed_transitions.includes('under_assessment') ? (
                <ReasonActionButton
                  label="Record assessment"
                  reasonLabel="Assessment notes"
                  onConfirm={(notes) => assess.mutateAsync({ notes })}
                />
              ) : null}
              {application.allowed_transitions.includes('recommended') ? (
                <ReasonActionButton
                  label="Recommend"
                  reasonLabel="Recommendation notes"
                  onConfirm={(notes) => recommend.mutateAsync({ notes })}
                />
              ) : null}
              {application.allowed_transitions.includes('approved') ? (
                <ActionButton label="Approve" onConfirm={() => approve.mutateAsync(undefined)} />
              ) : null}
              {application.allowed_transitions.includes('rejected') ? (
                <ReasonActionButton
                  label="Reject"
                  reasonLabel="Reason for rejection"
                  onConfirm={(reason) => reject.mutateAsync({ reason })}
                />
              ) : null}
              {application.allowed_transitions.includes('draft') ? (
                <ReasonActionButton
                  label="Return to draft"
                  variant="secondary"
                  reasonLabel="Reason"
                  onConfirm={(reason) => returnToDraft.mutateAsync({ reason })}
                />
              ) : null}
              {application.allowed_transitions.includes('withdrawn') ? (
                <ReasonActionButton
                  label="Withdraw"
                  variant="secondary"
                  reasonLabel="Reason for withdrawal"
                  onConfirm={(reason) => withdraw.mutateAsync({ reason })}
                />
              ) : null}
              {application.allowed_transitions.length === 0 ? (
                <p className="text-sm text-slate-500">No further transitions — this application is in a terminal state.</p>
              ) : null}
            </div>

            {application.decision_reason ? (
              <p className="mt-4 text-sm text-danger">Decision reason: {application.decision_reason}</p>
            ) : null}
            {application.withdrawal_reason ? (
              <p className="mt-4 text-sm text-slate-600">Withdrawal reason: {application.withdrawal_reason}</p>
            ) : null}
          </Card>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Card>
              <h2 className="mb-4 text-sm font-semibold text-slate-900">Application</h2>
              <div className="grid grid-cols-2 gap-4">
                <Detail
                  label="Merchant"
                  value={
                    application.merchant ? (
                      <Link href={`/merchants/${application.merchant.id}`} className="text-brand-700 hover:underline">
                        {application.merchant.full_name}
                      </Link>
                    ) : null
                  }
                />
                <Detail
                  label="Business"
                  value={
                    application.business ? (
                      <Link href={`/businesses/${application.business.id}`} className="text-brand-700 hover:underline">
                        {application.business.business_name}
                      </Link>
                    ) : null
                  }
                />
                <Detail
                  label="Loan product"
                  value={
                    application.loan_product ? (
                      <Link href={`/loan-products/${application.loan_product.id}`} className="text-brand-700 hover:underline">
                        {application.loan_product.name}
                      </Link>
                    ) : null
                  }
                />
                <Detail label="Branch" value={application.branch?.name} />
                <Detail label="Requested amount" value={formatMoney(application.requested.amount)} />
                <Detail label="Requested tenor" value={application.requested.tenor} />
                {application.approved ? (
                  <>
                    <Detail label="Approved amount" value={formatMoney(application.approved.amount)} />
                    <Detail label="Approved tenor" value={application.approved.tenor} />
                  </>
                ) : null}
              </div>
              {application.purpose ? (
                <>
                  <h3 className="mt-4 text-xs font-medium tracking-wide text-slate-500 uppercase">Purpose</h3>
                  <p className="mt-1 text-sm text-slate-700">{application.purpose}</p>
                </>
              ) : null}
            </Card>

            <Card>
              <h2 className="mb-4 text-sm font-semibold text-slate-900">Assessment &amp; recommendation</h2>
              <div className="space-y-4">
                <div>
                  <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">Assessment</p>
                  <p className="mt-0.5 text-sm text-slate-900">{application.assessment.notes ?? 'Not yet assessed.'}</p>
                  {application.assessment.assessed_by ? (
                    <p className="mt-1 text-xs text-slate-500">
                      {application.assessment.assessed_by.full_name} · {formatDateTime(application.assessment.assessed_at)}
                    </p>
                  ) : null}
                </div>
                <div>
                  <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">Recommendation</p>
                  <p className="mt-0.5 text-sm text-slate-900">
                    {application.recommendation.notes ?? 'Not yet recommended.'}
                  </p>
                  {application.recommendation.recommended_by ? (
                    <p className="mt-1 text-xs text-slate-500">
                      {application.recommendation.recommended_by.full_name} ·{' '}
                      {formatDateTime(application.recommendation.recommended_at)}
                    </p>
                  ) : null}
                </div>
              </div>
            </Card>
          </div>

          <Card>
            <div className="mb-4 flex items-center justify-between">
              <div>
                <h2 className="text-sm font-semibold text-slate-900">Guarantors</h2>
                {application.loan_product?.requires_guarantor ? (
                  <p className="mt-1 text-xs text-slate-500">
                    Requires at least {application.loan_product.minimum_guarantors} guarantor(s) —{' '}
                    {application.guarantors_satisfied ? 'satisfied' : 'not yet satisfied'}.
                  </p>
                ) : null}
              </div>
              {!addingGuarantor ? (
                <Button type="button" variant="secondary" size="sm" onClick={() => setAddingGuarantor(true)}>
                  Add guarantor
                </Button>
              ) : null}
            </div>

            {addingGuarantor ? (
              <div className="mb-4">
                <GuarantorForm applicationId={application.id} onDone={() => setAddingGuarantor(false)} />
              </div>
            ) : null}

            {application.guarantors.length > 0 ? (
              <div className="divide-y divide-slate-100">
                {application.guarantors.map((guarantor) => (
                  <div key={guarantor.id} className="flex items-center justify-between py-3">
                    <div>
                      <p className="font-medium text-slate-900">{guarantor.full_name}</p>
                      <p className="text-xs text-slate-500">
                        {guarantor.relationship} · {guarantor.phone}
                        {guarantor.monthly_income ? ` · ${formatMoney(guarantor.monthly_income)}/month` : ''}
                      </p>
                    </div>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => removeGuarantor.mutateAsync(guarantor.id)}
                    >
                      Remove
                    </Button>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-sm text-slate-500">No guarantors added yet.</p>
            )}
          </Card>

          <p className="text-xs text-slate-400">Last updated {formatDateTime(application.updated_at)}</p>
        </div>
      ) : null}
    </QueryState>
  );
}
