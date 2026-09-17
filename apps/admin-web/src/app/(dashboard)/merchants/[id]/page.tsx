'use client';

import { ApiError } from '@naipay/api-client';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { ActionButton, ReasonActionButton } from '@/components/ui/workflow-action';
import { useHasPermission } from '@/lib/auth/use-permission';
import { formatDate, formatDateTime, formatMoney, maskIdentityNumber, merchantLabel } from '@/lib/format';
import { useLoanApplications } from '@/lib/loan-applications/use-loan-applications';
import { useLoans } from '@/lib/loans/use-loans';
import { useRepayments } from '@/lib/repayments/use-repayments';
import {
  useApproveMerchant,
  useMerchant,
  useReinstateMerchant,
  useRejectMerchant,
  useReturnMerchantToDraft,
  useSubmitMerchant,
  useSuspendMerchant,
  useUpdateMerchant,
  useVerifyMerchant,
} from '@/lib/merchants/use-merchants';
import type { KycStatusKey, Merchant, MerchantFormInput, OnboardingStatusKey } from '@/lib/merchants/types';

const onboardingTone: Record<OnboardingStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  draft: 'neutral',
  submitted: 'info',
  pending_verification: 'warning',
  pending_approval: 'warning',
  approved: 'success',
  rejected: 'danger',
  suspended: 'danger',
  closed: 'neutral',
};

const kycTone: Record<KycStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  not_started: 'neutral',
  pending: 'warning',
  verified: 'success',
  rejected: 'danger',
  expired: 'warning',
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

/**
 * Everything a merchant has running, on the merchant's own page: their loans,
 * recent repayments and applications, each linking straight through — and the
 * "start a new one" actions — so staff don't have to leave for the Loans or
 * Repayments sections and filter back down to this person.
 */
function MerchantActivity({ merchantId }: { merchantId: number }) {
  const canViewLoans = useHasPermission('loans.view');
  const canViewRepayments = useHasPermission('repayments.view');
  const canViewApplications = useHasPermission('loan_applications.view');
  const canCreateApplication = useHasPermission('loan_applications.create');
  const canRecordRepayment = useHasPermission('repayments.record');

  const loans = useLoans(canViewLoans ? { merchant_id: merchantId, per_page: 10 } : undefined);
  const repayments = useRepayments(
    canViewRepayments ? { merchant_id: merchantId, per_page: 5, sort: '-payment_date' } : undefined,
  );
  const applications = useLoanApplications(
    canViewApplications ? { merchant_id: merchantId, per_page: 5, sort: '-created_at' } : undefined,
  );

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      {canViewLoans ? (
        <Card>
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-sm font-semibold text-slate-900">Loans</h2>
            {canCreateApplication ? (
              <Link
                href={`/loan-applications/new?merchant=${merchantId}`}
                className={buttonVariants({ variant: 'secondary', size: 'sm' })}
              >
                New loan application
              </Link>
            ) : null}
          </div>
          {loans.data && loans.data.items.length > 0 ? (
            <div className="divide-y divide-slate-100">
              {loans.data.items.map((loan) => (
                <div key={loan.id} className="flex items-center justify-between gap-3 py-3">
                  <div>
                    <Link href={`/loans/${loan.id}`} className="font-medium text-brand-700 hover:underline">
                      {loan.loan_product?.name ?? 'Loan'}
                    </Link>
                    <p className="numeric text-xs text-slate-500">
                      {formatMoney(loan.terms.principal_amount)}
                      {loan.payments?.total_paid ? ` · ${formatMoney(loan.payments.total_paid)} paid` : ''}
                      {loan.outstanding && !loan.is_fully_paid
                        ? ` · ${formatMoney(loan.outstanding.principal)} outstanding`
                        : ''}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge tone={loan.status === 'disbursed' ? 'success' : loan.status === 'written_off' ? 'danger' : 'info'}>
                      {loan.status_label}
                    </Badge>
                    {loan.is_fully_paid ? <Badge tone="success">Fully paid</Badge> : null}
                    {canRecordRepayment && loan.status === 'disbursed' ? (
                      <Link
                        href={`/repayments/new?loan=${loan.id}`}
                        className="text-xs font-medium text-brand-700 hover:underline"
                      >
                        Record repayment
                      </Link>
                    ) : null}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-slate-500">No loans for this merchant yet.</p>
          )}
        </Card>
      ) : null}

      {canViewRepayments ? (
        <Card>
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-sm font-semibold text-slate-900">Recent repayments</h2>
            {canRecordRepayment ? (
              <Link href="/repayments/new" className={buttonVariants({ variant: 'secondary', size: 'sm' })}>
                Record repayment
              </Link>
            ) : null}
          </div>
          {repayments.data && repayments.data.items.length > 0 ? (
            <div className="divide-y divide-slate-100">
              {repayments.data.items.map((repayment) => (
                <Link
                  key={repayment.id}
                  href={`/repayments/${repayment.id}`}
                  className="flex items-center justify-between py-3 hover:bg-slate-50"
                >
                  <div>
                    <p className="numeric font-medium text-slate-900">{formatMoney(repayment.amount)}</p>
                    <p className="text-xs text-slate-500">{formatDate(repayment.payment_date)}</p>
                  </div>
                  <Badge tone={repayment.status === 'approved' ? 'success' : repayment.status === 'rejected' ? 'danger' : 'info'}>
                    {repayment.status_label}
                  </Badge>
                </Link>
              ))}
            </div>
          ) : (
            <p className="text-sm text-slate-500">No repayments recorded for this merchant yet.</p>
          )}
        </Card>
      ) : null}

      {canViewApplications ? (
        <Card>
          <h2 className="mb-4 text-sm font-semibold text-slate-900">Loan applications</h2>
          {applications.data && applications.data.items.length > 0 ? (
            <div className="divide-y divide-slate-100">
              {applications.data.items.map((application) => (
                <Link
                  key={application.id}
                  href={`/loan-applications/${application.id}`}
                  className="flex items-center justify-between py-3 hover:bg-slate-50"
                >
                  <div>
                    <p className="numeric font-medium text-slate-900">
                      {formatMoney(application.requested.amount)}
                    </p>
                    <p className="text-xs text-slate-500">{formatDate(application.created_at)}</p>
                  </div>
                  <Badge tone={application.status === 'approved' ? 'success' : application.status === 'rejected' ? 'danger' : 'info'}>
                    {application.status_label}
                  </Badge>
                </Link>
              ))}
            </div>
          ) : (
            <p className="text-sm text-slate-500">No loan applications for this merchant yet.</p>
          )}
        </Card>
      ) : null}
    </div>
  );
}

function toFormInput(merchant: Merchant): MerchantFormInput {
  return {
    first_name: merchant.first_name,
    middle_name: merchant.middle_name ?? '',
    last_name: merchant.last_name,
    date_of_birth: merchant.date_of_birth ?? '',
    gender: merchant.gender ?? '',
    phone: merchant.phone,
    alternative_phone: merchant.alternative_phone ?? '',
    email: merchant.email ?? '',
    residential_address: merchant.residential_address ?? '',
    city: merchant.city ?? '',
    state: merchant.state ?? '',
    country: merchant.country ?? '',
    bvn: merchant.identity.bvn ?? '',
    nin: merchant.identity.nin ?? '',
  };
}

export default function MerchantDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: merchant, isLoading, error } = useMerchant(id);
  const canCreateBusiness = useHasPermission('businesses.create');
  const canUpdateMerchant = useHasPermission('merchants.update');

  const submit = useSubmitMerchant(id);
  const verify = useVerifyMerchant(id);
  const approve = useApproveMerchant(id);
  const reject = useRejectMerchant(id);
  const suspend = useSuspendMerchant(id);
  const reinstate = useReinstateMerchant(id);
  const returnToDraft = useReturnMerchantToDraft(id);

  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<MerchantFormInput | null>(null);

  const updateMerchant = useUpdateMerchant(id);
  const updateError = updateMerchant.error instanceof ApiError ? updateMerchant.error : null;

  function startEditing(current: Merchant) {
    setForm(toFormInput(current));
    setEditing(true);
  }

  function set<K extends keyof MerchantFormInput>(key: K, value: MerchantFormInput[K]) {
    if (!form) return;
    setForm({ ...form, [key]: value });
  }

  async function handleSave(event: React.FormEvent) {
    event.preventDefault();
    if (!form) return;

    try {
      await updateMerchant.mutateAsync(form);
      setEditing(false);
    } catch {
      // Surfaced via `updateError` above, rendered from the mutation state.
    }
  }

  return (
    <QueryState isLoading={isLoading} error={error}>
      {merchant ? (
        <div className="space-y-6">
          <PageHeader
            title={merchant.full_name}
            description={`${merchantLabel(merchant)} · ${merchant.phone}`}
            actions={
              <div className="flex items-center gap-2">
                <Badge tone={onboardingTone[merchant.onboarding_status]}>
                  {merchant.onboarding_status_label}
                </Badge>
                <Badge tone={kycTone[merchant.kyc_status]}>{merchant.kyc_status_label}</Badge>
                {canUpdateMerchant && merchant.is_editable && !editing ? (
                  <Button type="button" variant="secondary" onClick={() => startEditing(merchant)}>
                    Edit
                  </Button>
                ) : null}
              </div>
            }
          />

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Workflow</h2>
            <div className="flex flex-wrap items-start gap-3">
              {merchant.allowed_transitions.includes('submitted') ? (
                <ActionButton label="Submit for verification" onConfirm={() => submit.mutateAsync(undefined)} />
              ) : null}

              {(merchant.allowed_transitions.includes('pending_verification') ||
                merchant.allowed_transitions.includes('pending_approval')) ? (
                <ActionButton label="Verify KYC" onConfirm={() => verify.mutateAsync(undefined)} />
              ) : null}

              {merchant.allowed_transitions.includes('approved') ? (
                merchant.onboarding_status === 'suspended' ? (
                  <ActionButton label="Reinstate" onConfirm={() => reinstate.mutateAsync(undefined)} />
                ) : (
                  <ActionButton label="Approve" onConfirm={() => approve.mutateAsync(undefined)} />
                )
              ) : null}

              {merchant.allowed_transitions.includes('suspended') ? (
                <ReasonActionButton
                  label="Suspend"
                  reasonLabel="Reason for suspension"
                  onConfirm={(reason) => suspend.mutateAsync({ reason })}
                />
              ) : null}

              {merchant.allowed_transitions.includes('rejected') ? (
                <ReasonActionButton
                  label="Reject"
                  reasonLabel="Reason for rejection"
                  onConfirm={(reason) => reject.mutateAsync({ reason })}
                />
              ) : null}

              {merchant.allowed_transitions.includes('draft') ? (
                <ReasonActionButton
                  label="Return to draft"
                  variant="secondary"
                  reasonLabel="Reason"
                  onConfirm={(reason) => returnToDraft.mutateAsync({ reason })}
                />
              ) : null}

              {merchant.allowed_transitions.length === 0 ? (
                <p className="text-sm text-slate-500">No further transitions — this merchant is in a terminal state.</p>
              ) : null}
            </div>

            {merchant.rejection_reason ? (
              <p className="mt-4 text-sm text-danger">Rejection reason: {merchant.rejection_reason}</p>
            ) : null}
            {merchant.suspension_reason ? (
              <p className="mt-4 text-sm text-danger">Suspension reason: {merchant.suspension_reason}</p>
            ) : null}
          </Card>

          {editing && form ? (
            <Card>
              <div className="mb-4 flex items-center justify-between">
                <h2 className="text-sm font-semibold text-slate-900">Edit customer details</h2>
                <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(false)}>
                  Cancel
                </Button>
              </div>
              <form onSubmit={handleSave} className="space-y-5">
                {updateError && !updateError.isValidation ? (
                  <Alert tone="error" reference={updateError.correlationId}>
                    {updateError.message}
                  </Alert>
                ) : null}

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <Field
                    label="First name"
                    required
                    value={form.first_name}
                    onChange={(event) => set('first_name', event.target.value)}
                    error={updateError?.fieldError('first_name')}
                  />
                  <Field
                    label="Middle name"
                    value={form.middle_name}
                    onChange={(event) => set('middle_name', event.target.value)}
                    error={updateError?.fieldError('middle_name')}
                  />
                  <Field
                    label="Last name"
                    required
                    value={form.last_name}
                    onChange={(event) => set('last_name', event.target.value)}
                    error={updateError?.fieldError('last_name')}
                  />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field
                    label="Date of birth"
                    type="date"
                    value={form.date_of_birth}
                    onChange={(event) => set('date_of_birth', event.target.value)}
                    error={updateError?.fieldError('date_of_birth')}
                  />
                  <Field
                    label="Gender"
                    value={form.gender}
                    onChange={(event) => set('gender', event.target.value)}
                    error={updateError?.fieldError('gender')}
                  />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field
                    label="Phone"
                    required
                    value={form.phone}
                    onChange={(event) => set('phone', event.target.value)}
                    error={updateError?.fieldError('phone')}
                  />
                  <Field
                    label="Alternative phone"
                    value={form.alternative_phone}
                    onChange={(event) => set('alternative_phone', event.target.value)}
                    error={updateError?.fieldError('alternative_phone')}
                  />
                </div>

                <Field
                  label="Email"
                  type="email"
                  value={form.email}
                  onChange={(event) => set('email', event.target.value)}
                  error={updateError?.fieldError('email')}
                />

                <Field
                  label="Residential address"
                  value={form.residential_address}
                  onChange={(event) => set('residential_address', event.target.value)}
                  error={updateError?.fieldError('residential_address')}
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <Field
                    label="City"
                    value={form.city}
                    onChange={(event) => set('city', event.target.value)}
                    error={updateError?.fieldError('city')}
                  />
                  <Field
                    label="State"
                    value={form.state}
                    onChange={(event) => set('state', event.target.value)}
                    error={updateError?.fieldError('state')}
                  />
                  <Field
                    label="Country"
                    value={form.country}
                    onChange={(event) => set('country', event.target.value)}
                    error={updateError?.fieldError('country')}
                  />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field
                    label="BVN"
                    value={form.bvn}
                    onChange={(event) => set('bvn', event.target.value)}
                    error={updateError?.fieldError('bvn')}
                  />
                  <Field
                    label="NIN"
                    value={form.nin}
                    onChange={(event) => set('nin', event.target.value)}
                    error={updateError?.fieldError('nin')}
                  />
                </div>

                <Button type="submit" loading={updateMerchant.isPending}>
                  Save changes
                </Button>
              </form>
            </Card>
          ) : null}

          {!editing ? (
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              <Card>
                <h2 className="mb-4 text-sm font-semibold text-slate-900">Profile</h2>
                <div className="grid grid-cols-2 gap-4">
                  <Detail label="Date of birth" value={merchant.date_of_birth} />
                  <Detail label="Gender" value={merchant.gender} />
                  <Detail label="Email" value={merchant.email} />
                  <Detail label="Alternative phone" value={merchant.alternative_phone} />
                  <Detail
                    label="Address"
                    value={[merchant.residential_address, merchant.city, merchant.state, merchant.country]
                      .filter(Boolean)
                      .join(', ')}
                  />
                  <Detail label="Branch" value={merchant.branch?.name} />
                  <Detail label="Assigned officer" value={merchant.assigned_officer?.full_name} />
                  <Detail label="Risk rating" value={merchant.risk_rating_label} />
                </div>
              </Card>

              <Card>
                <h2 className="mb-4 text-sm font-semibold text-slate-900">Identity</h2>
                <div className="grid grid-cols-2 gap-4">
                  <Detail
                    label="BVN"
                    value={merchant.identity.has_bvn ? maskIdentityNumber(merchant.identity.bvn_masked) : 'Not provided'}
                  />
                  <Detail
                    label="NIN"
                    value={merchant.identity.has_nin ? maskIdentityNumber(merchant.identity.nin_masked) : 'Not provided'}
                  />
                </div>

                <h2 className="mt-6 mb-4 text-sm font-semibold text-slate-900">Wallet account</h2>
                {merchant.account ? (
                  <div className="grid grid-cols-2 gap-4">
                    <Detail label="Account number" value={merchant.account.account_number_formatted} />
                    <Detail label="Available balance" value={formatMoney(merchant.account.available_balance)} />
                    <Detail label="Status" value={merchant.account.status} />
                  </div>
                ) : (
                  <p className="text-sm text-slate-500">No account yet — created once the merchant is approved.</p>
                )}
              </Card>
            </div>
          ) : null}

          <Card>
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-sm font-semibold text-slate-900">
                Businesses {merchant.businesses_count ? `(${merchant.businesses_count})` : ''}
              </h2>
              {canCreateBusiness ? (
                <Link
                  href={`/merchants/${merchant.id}/businesses/new`}
                  className={buttonVariants({ variant: 'secondary', size: 'sm' })}
                >
                  Add business
                </Link>
              ) : null}
            </div>

            {merchant.businesses && merchant.businesses.length > 0 ? (
              <div className="divide-y divide-slate-100">
                {merchant.businesses.map((business) => (
                  <Link
                    key={business.id}
                    href={`/businesses/${business.id}`}
                    className="flex items-center justify-between py-3 hover:bg-slate-50"
                  >
                    <div>
                      <p className="font-medium text-slate-900">{business.business_name}</p>
                      <p className="numeric text-xs text-slate-500">{business.business_number}</p>
                    </div>
                    <Badge tone={business.is_verified ? 'success' : 'neutral'}>
                      {business.verification_status_label}
                    </Badge>
                  </Link>
                ))}
              </div>
            ) : (
              <p className="text-sm text-slate-500">No businesses registered for this merchant yet.</p>
            )}
          </Card>

          {merchant.loan_summary ? (
            <Card>
              <h2 className="mb-4 text-sm font-semibold text-slate-900">Loan summary</h2>
              {merchant.loan_summary.disbursed_loan_count > 0 ? (
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                  <Detail label="Total paid (incl. interest)" value={formatMoney(merchant.loan_summary.total_paid)} />
                  <Detail label="Outstanding" value={formatMoney(merchant.loan_summary.total_outstanding)} />
                  <Detail label="Disbursed loans" value={String(merchant.loan_summary.disbursed_loan_count)} />
                  <Detail
                    label="Status"
                    value={
                      <Badge tone={merchant.loan_summary.all_loans_fully_paid ? 'success' : 'info'}>
                        {merchant.loan_summary.all_loans_fully_paid ? 'Fully paid' : 'Actively repaying'}
                      </Badge>
                    }
                  />
                </div>
              ) : (
                <p className="text-sm text-slate-500">No disbursed loans yet.</p>
              )}
            </Card>
          ) : null}

          <MerchantActivity merchantId={merchant.id} />

          <p className="text-xs text-slate-400">Last updated {formatDateTime(merchant.updated_at)}</p>
        </div>
      ) : null}
    </QueryState>
  );
}
