'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';

import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { ActionButton, ReasonActionButton } from '@/components/ui/workflow-action';
import { formatDateTime, formatMoney } from '@/lib/format';
import {
  useBusiness,
  useChangeBusinessStatus,
  useRejectBusinessVerification,
  useVerifyBusiness,
} from '@/lib/businesses/use-businesses';
import type { BusinessStatusKey, BusinessVerificationStatusKey } from '@/lib/businesses/types';

const verificationTone: Record<BusinessVerificationStatusKey, 'success' | 'warning' | 'danger' | 'neutral'> = {
  unverified: 'neutral',
  pending: 'warning',
  verified: 'success',
  rejected: 'danger',
};

const statusTransitions: Record<BusinessStatusKey, BusinessStatusKey[]> = {
  active: ['inactive', 'suspended', 'closed'],
  inactive: ['active', 'closed'],
  suspended: ['active', 'closed'],
  closed: [],
};

const statusLabels: Record<BusinessStatusKey, string> = {
  active: 'Active',
  inactive: 'Inactive',
  suspended: 'Suspended',
  closed: 'Closed',
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

export default function BusinessDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: business, isLoading, error } = useBusiness(id);

  const verify = useVerifyBusiness(id);
  const rejectVerification = useRejectBusinessVerification(id);
  const changeStatus = useChangeBusinessStatus(id);

  return (
    <QueryState isLoading={isLoading} error={error}>
      {business ? (
        <div className="space-y-6">
          <PageHeader
            title={business.business_name}
            description={`${business.business_number} · ${business.business_type_label}`}
            actions={
              <div className="flex items-center gap-2">
                <Badge tone={verificationTone[business.verification_status]}>
                  {business.verification_status_label}
                </Badge>
                <Badge tone="neutral">{business.status_label}</Badge>
              </div>
            }
          />

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Workflow</h2>
            <div className="flex flex-wrap items-start gap-3">
              {!business.is_verified ? (
                <ActionButton label="Verify" onConfirm={() => verify.mutateAsync()} />
              ) : null}

              {business.verification_status !== 'rejected' && !business.is_verified ? (
                <ReasonActionButton
                  label="Reject verification"
                  reasonLabel="Reason for rejection"
                  onConfirm={(reason) => rejectVerification.mutateAsync({ reason })}
                />
              ) : null}

              {statusTransitions[business.status].map((target) => (
                <ReasonActionButton
                  key={target}
                  label={`Mark as ${statusLabels[target]}`}
                  variant={target === 'closed' || target === 'suspended' ? 'destructive' : 'secondary'}
                  reasonLabel="Reason"
                  onConfirm={(reason) => changeStatus.mutateAsync({ status: target, reason })}
                />
              ))}
            </div>
          </Card>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Card>
              <h2 className="mb-4 text-sm font-semibold text-slate-900">Details</h2>
              <div className="grid grid-cols-2 gap-4">
                <Detail label="Registered name" value={business.registered_business_name} />
                <Detail label="CAC number" value={business.cac_registration_number} />
                <Detail label="Category" value={business.category?.name} />
                <Detail label="Subcategory" value={business.subcategory?.name} />
                <Detail label="Phone" value={business.business_phone} />
                <Detail label="Email" value={business.business_email} />
                <Detail
                  label="Address"
                  value={[business.business_address, business.city, business.state, business.country]
                    .filter(Boolean)
                    .join(', ')}
                />
                <Detail label="Website" value={business.website} />
                <Detail label="Year established" value={business.year_established} />
                <Detail label="Employees" value={business.number_of_employees} />
              </div>
            </Card>

            <Card>
              <h2 className="mb-4 text-sm font-semibold text-slate-900">Financials</h2>
              <div className="grid grid-cols-2 gap-4">
                <Detail label="Estimated monthly revenue" value={formatMoney(business.estimated_monthly_revenue)} />
                <Detail label="Estimated monthly expenses" value={formatMoney(business.estimated_monthly_expenses)} />
                <Detail label="Average monthly sales" value={formatMoney(business.average_monthly_sales)} />
                <Detail label="Declared monthly surplus" value={formatMoney(business.declared_monthly_surplus)} />
              </div>

              {business.business_description ? (
                <>
                  <h2 className="mt-6 mb-2 text-sm font-semibold text-slate-900">Description</h2>
                  <p className="text-sm text-slate-700">{business.business_description}</p>
                </>
              ) : null}
            </Card>
          </div>

          <Link href={`/merchants/${business.merchant_id}`} className="text-sm text-brand-700 hover:underline">
            View merchant
          </Link>

          <p className="text-xs text-slate-400">Last updated {formatDateTime(business.updated_at)}</p>
        </div>
      ) : null}
    </QueryState>
  );
}
