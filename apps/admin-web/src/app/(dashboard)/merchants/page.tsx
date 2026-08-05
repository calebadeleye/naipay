'use client';

import Link from 'next/link';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field } from '@/components/ui/field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { QueryState } from '@/components/ui/query-state';
import { SelectField } from '@/components/ui/select';
import { formatDate } from '@/lib/format';
import { useMerchants } from '@/lib/merchants/use-merchants';
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

const onboardingOptions = [
  { value: '', label: 'All onboarding statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'submitted', label: 'Submitted' },
  { value: 'pending_verification', label: 'Pending verification' },
  { value: 'pending_approval', label: 'Pending approval' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'closed', label: 'Closed' },
];

export default function MerchantsPage() {
  const [search, setSearch] = useState('');
  const [onboardingStatus, setOnboardingStatus] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading, error } = useMerchants({
    search: search || undefined,
    onboarding_status: onboardingStatus || undefined,
    page,
    per_page: 20,
  });

  return (
    <>
      <PageHeader
        title="Merchants"
        description="Every merchant onboarded to Naipay, from a draft profile through approval and, eventually, closure."
        actions={
          <Link href="/merchants/new" className={buttonVariants({ variant: 'primary' })}>
            New merchant
          </Link>
        }
      />

      <div className="flex flex-wrap gap-3">
        <Field
          label="Search"
          placeholder="Name, phone, email or merchant number"
          value={search}
          onChange={(event) => {
            setPage(1);
            setSearch(event.target.value);
          }}
          className="w-72"
        />
        <SelectField
          label="Onboarding status"
          options={onboardingOptions}
          value={onboardingStatus}
          onChange={(event) => {
            setPage(1);
            setOnboardingStatus(event.target.value);
          }}
          className="w-56"
        />
      </div>

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-4 py-3">Merchant</th>
                  <th className="px-4 py-3">Phone</th>
                  <th className="px-4 py-3">Onboarding</th>
                  <th className="px-4 py-3">KYC</th>
                  <th className="px-4 py-3">Branch</th>
                  <th className="px-4 py-3">Joined</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((merchant) => (
                  <tr key={merchant.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link href={`/merchants/${merchant.id}`} className="font-medium text-brand-700 hover:underline">
                        {merchant.full_name}
                      </Link>
                      <p className="numeric text-xs text-slate-500">{merchant.merchant_number}</p>
                    </td>
                    <td className="numeric px-4 py-3 text-slate-700">{merchant.phone}</td>
                    <td className="px-4 py-3">
                      <Badge tone={onboardingTone[merchant.onboarding_status]}>
                        {merchant.onboarding_status_label}
                      </Badge>
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={kycTone[merchant.kyc_status]}>{merchant.kyc_status_label}</Badge>
                    </td>
                    <td className="px-4 py-3 text-slate-700">{merchant.branch?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-slate-500">{formatDate(merchant.created_at)}</td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                      No merchants match these filters.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
            <Pagination meta={data.pagination} page={page} onPageChange={setPage} />
          </Card>
        ) : null}
      </QueryState>
    </>
  );
}
