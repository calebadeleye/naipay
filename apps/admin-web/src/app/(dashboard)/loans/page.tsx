'use client';

import Link from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { Suspense, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Field } from '@/components/ui/field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { QueryState } from '@/components/ui/query-state';
import { SelectField } from '@/components/ui/select';
import { formatDate, formatMoney, merchantLabel } from '@/lib/format';
import { useLoans } from '@/lib/loans/use-loans';
import type { LoanStatusKey } from '@/lib/loans/types';
import { useStaffList } from '@/lib/staff/use-staff';

/**
 * Filters passed only in the URL — set when a loan portfolio metric is
 * drilled into. They have no visible control here; a banner offers to clear
 * them. `status`, `loan_officer_id` and the arrears filter do have controls
 * and are handled separately.
 */
const DRILL_ONLY_KEYS = [
  'loan_product_id',
  'branch_id',
  'merchant_id',
  'disbursement_date_from',
  'disbursement_date_to',
  'max_days_past_due',
] as const;

const statusTone: Record<LoanStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  pending_approval: 'warning',
  pending_disbursement: 'info',
  disbursed: 'success',
  written_off: 'danger',
};

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'pending_approval', label: 'Pending approval' },
  { value: 'pending_disbursement', label: 'Pending disbursement' },
  { value: 'disbursed', label: 'Disbursed' },
  { value: 'written_off', label: 'Written off' },
];

const arrearsOptions = [
  { value: '', label: 'Any' },
  { value: 'overdue', label: 'In arrears (any)' },
  { value: '1', label: '1+ days past due' },
  { value: '30', label: '30+ days past due' },
  { value: '60', label: '60+ days past due' },
  { value: '90', label: '90+ days past due' },
];

export default function LoansPage() {
  return (
    <Suspense fallback={<PageHeader title="Loans" />}>
      <LoansList />
    </Suspense>
  );
}

function LoansList() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  const status = searchParams.get('status') ?? '';
  const officer = searchParams.get('loan_officer_id') ?? '';
  const arrears =
    searchParams.get('overdue') === '1' ? 'overdue' : (searchParams.get('min_days_past_due') ?? '');

  const drill = useMemo(() => {
    const entries: Record<string, string> = {};
    for (const key of DRILL_ONLY_KEYS) {
      const value = searchParams.get(key);
      if (value) entries[key] = value;
    }
    return entries;
  }, [searchParams]);

  const officers = useStaffList({ per_page: 200 });

  function setParam(patch: Record<string, string | null>) {
    const next = new URLSearchParams(searchParams.toString());
    for (const [key, value] of Object.entries(patch)) {
      if (value === null || value === '') next.delete(key);
      else next.set(key, value);
    }
    setPage(1);
    const qs = next.toString();
    router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
  }

  const { data, isLoading, error } = useLoans({
    search: search || undefined,
    status: status || undefined,
    loan_officer_id: officer || undefined,
    overdue: arrears === 'overdue' ? 1 : undefined,
    min_days_past_due: /^\d+$/.test(arrears) ? Number(arrears) : undefined,
    page,
    per_page: 20,
    ...drill,
  });

  const hasDrill = Object.keys(drill).length > 0;

  return (
    <>
      {hasDrill ? (
        <div className="glass-surface flex flex-wrap items-center gap-3 rounded-2xl px-5 py-3 text-sm text-slate-600">
          <span>Filtered from the loan portfolio dashboard.</span>
          <button
            type="button"
            onClick={() => router.replace('/loans')}
            className="font-medium text-brand-700 hover:underline"
          >
            Clear
          </button>
        </div>
      ) : null}

      <PageHeader
        title="Loans"
        description="Loans move here automatically once their application is approved — there is no manual creation."
      />

      <div className="flex flex-wrap gap-3">
        <Field
          label="Search"
          placeholder="Account number or merchant name"
          value={search}
          onChange={(event) => {
            setPage(1);
            setSearch(event.target.value);
          }}
          className="w-72"
        />
        <SelectField
          label="Status"
          options={statusOptions}
          value={status}
          onChange={(event) => setParam({ status: event.target.value })}
          className="w-52"
        />
        <SelectField
          label="Loan officer"
          options={[
            { value: '', label: 'All officers' },
            ...(officers.data?.items ?? []).map((staff) => ({ value: String(staff.id), label: staff.full_name })),
          ]}
          value={officer}
          onChange={(event) => setParam({ loan_officer_id: event.target.value })}
          className="w-56"
        />
        <SelectField
          label="Arrears"
          options={arrearsOptions}
          value={arrears}
          onChange={(event) => {
            const value = event.target.value;
            setParam({
              overdue: value === 'overdue' ? '1' : null,
              min_days_past_due: /^\d+$/.test(value) ? value : null,
              max_days_past_due: null,
            });
          }}
          className="w-52"
        />
      </div>

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-4 py-3">Account</th>
                  <th className="px-4 py-3">Merchant</th>
                  <th className="px-4 py-3">Product</th>
                  <th className="px-4 py-3 text-right">Principal</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Created</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((loan) => (
                  <tr key={loan.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link href={`/loans/${loan.id}`} className="numeric font-medium text-brand-700 hover:underline">
                        {merchantLabel(loan.merchant)}
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-slate-700">{loan.merchant?.full_name ?? '—'}</td>
                    <td className="px-4 py-3 text-slate-700">{loan.loan_product?.name ?? '—'}</td>
                    <td className="numeric px-4 py-3 text-right text-slate-700">
                      {formatMoney(loan.terms.principal_amount)}
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone[loan.status]}>{loan.status_label}</Badge>
                    </td>
                    <td className="px-4 py-3 text-slate-500">{formatDate(loan.created_at)}</td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                      No loans match these filters.
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
