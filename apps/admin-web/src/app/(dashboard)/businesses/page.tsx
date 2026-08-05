'use client';

import Link from 'next/link';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Field } from '@/components/ui/field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { QueryState } from '@/components/ui/query-state';
import { SelectField } from '@/components/ui/select';
import { formatMoney } from '@/lib/format';
import { useBusinesses } from '@/lib/businesses/use-businesses';
import type { BusinessVerificationStatusKey } from '@/lib/businesses/types';

const verificationTone: Record<BusinessVerificationStatusKey, 'success' | 'warning' | 'danger' | 'neutral'> = {
  unverified: 'neutral',
  pending: 'warning',
  verified: 'success',
  rejected: 'danger',
};

const verificationOptions = [
  { value: '', label: 'All verification statuses' },
  { value: 'unverified', label: 'Unverified' },
  { value: 'pending', label: 'Pending' },
  { value: 'verified', label: 'Verified' },
  { value: 'rejected', label: 'Rejected' },
];

export default function BusinessesPage() {
  const [search, setSearch] = useState('');
  const [verificationStatus, setVerificationStatus] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading, error } = useBusinesses({
    search: search || undefined,
    verification_status: verificationStatus || undefined,
    page,
    per_page: 20,
  });

  return (
    <>
      <PageHeader
        title="Businesses"
        description="Every business registered under a merchant, and its verification standing."
      />

      <div className="flex flex-wrap gap-3">
        <Field
          label="Search"
          placeholder="Business name or number"
          value={search}
          onChange={(event) => {
            setPage(1);
            setSearch(event.target.value);
          }}
          className="w-72"
        />
        <SelectField
          label="Verification"
          options={verificationOptions}
          value={verificationStatus}
          onChange={(event) => {
            setPage(1);
            setVerificationStatus(event.target.value);
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
                  <th className="px-4 py-3">Business</th>
                  <th className="px-4 py-3">Type</th>
                  <th className="px-4 py-3">Category</th>
                  <th className="px-4 py-3">Verification</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3 text-right">Avg monthly sales</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((business) => (
                  <tr key={business.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link href={`/businesses/${business.id}`} className="font-medium text-brand-700 hover:underline">
                        {business.business_name}
                      </Link>
                      <p className="numeric text-xs text-slate-500">{business.business_number}</p>
                    </td>
                    <td className="px-4 py-3 text-slate-700">{business.business_type_label}</td>
                    <td className="px-4 py-3 text-slate-700">{business.category?.name ?? '—'}</td>
                    <td className="px-4 py-3">
                      <Badge tone={verificationTone[business.verification_status]}>
                        {business.verification_status_label}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 text-slate-700">{business.status_label}</td>
                    <td className="numeric px-4 py-3 text-right text-slate-700">
                      {formatMoney(business.average_monthly_sales)}
                    </td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                      No businesses match these filters.
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
