'use client';

import { useState } from 'react';

import { StatCard } from '@/components/ui/card';
import { Field } from '@/components/ui/field';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { formatAmountString, formatNumber } from '@/lib/format';
import { useCollections } from '@/lib/reports/use-reports';

function startOfMonth(): string {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10);
}

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

export default function CollectionsPage() {
  const [from, setFrom] = useState(startOfMonth());
  const [to, setTo] = useState(today());

  const { data, isLoading, error } = useCollections(from, to);

  return (
    <>
      <PageHeader
        title="Collections"
        description="Approved repayments within the period, and how they were allocated."
        actions={
          <div className="flex items-end gap-3">
            <Field
              label="From"
              type="date"
              value={from}
              onChange={(event) => setFrom(event.target.value)}
              className="w-40"
            />
            <Field
              label="To"
              type="date"
              value={to}
              onChange={(event) => setTo(event.target.value)}
              className="w-40"
            />
          </div>
        }
      />

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <div className="space-y-6">
            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <StatCard label="Repayments collected" value={formatNumber(data.count)} />
              <StatCard
                label="Total collected"
                value={formatAmountString(data.total_collected)}
                tone="success"
              />
            </section>

            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
              <StatCard label="Principal" value={formatAmountString(data.allocated_principal)} />
              <StatCard label="Interest" value={formatAmountString(data.allocated_interest)} />
              <StatCard label="Fees" value={formatAmountString(data.allocated_fee)} />
              <StatCard
                label="Excess (merchant balance)"
                value={formatAmountString(data.allocated_excess)}
              />
              <StatCard
                label="Unallocated (suspense)"
                value={formatAmountString(data.allocated_unallocated)}
                tone={data.allocated_unallocated !== '0.00' ? 'warning' : 'default'}
              />
            </section>
          </div>
        ) : null}
      </QueryState>
    </>
  );
}
