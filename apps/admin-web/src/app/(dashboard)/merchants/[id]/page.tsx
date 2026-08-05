'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';

import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { ActionButton, ReasonActionButton } from '@/components/ui/workflow-action';
import { formatDateTime, formatMoney, maskIdentityNumber } from '@/lib/format';
import {
  useApproveMerchant,
  useMerchant,
  useReinstateMerchant,
  useRejectMerchant,
  useReturnMerchantToDraft,
  useSubmitMerchant,
  useSuspendMerchant,
  useVerifyMerchant,
} from '@/lib/merchants/use-merchants';
import type { KycStatusKey, OnboardingStatusKey } from '@/lib/merchants/types';

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

export default function MerchantDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: merchant, isLoading, error } = useMerchant(id);

  const submit = useSubmitMerchant(id);
  const verify = useVerifyMerchant(id);
  const approve = useApproveMerchant(id);
  const reject = useRejectMerchant(id);
  const suspend = useSuspendMerchant(id);
  const reinstate = useReinstateMerchant(id);
  const returnToDraft = useReturnMerchantToDraft(id);

  return (
    <QueryState isLoading={isLoading} error={error}>
      {merchant ? (
        <div className="space-y-6">
          <PageHeader
            title={merchant.full_name}
            description={`${merchant.merchant_number} · ${merchant.phone}`}
            actions={
              <div className="flex items-center gap-2">
                <Badge tone={onboardingTone[merchant.onboarding_status]}>
                  {merchant.onboarding_status_label}
                </Badge>
                <Badge tone={kycTone[merchant.kyc_status]}>{merchant.kyc_status_label}</Badge>
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

          <Card>
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-sm font-semibold text-slate-900">
                Businesses {merchant.businesses_count ? `(${merchant.businesses_count})` : ''}
              </h2>
              <Link
                href={`/merchants/${merchant.id}/businesses/new`}
                className={buttonVariants({ variant: 'secondary', size: 'sm' })}
              >
                Add business
              </Link>
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

          <p className="text-xs text-slate-400">Last updated {formatDateTime(merchant.updated_at)}</p>
        </div>
      ) : null}
    </QueryState>
  );
}
