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
import { useBankAccounts } from '@/lib/bank-accounts/use-bank-accounts';
import { useHasPermission } from '@/lib/auth/use-permission';
import type { BankAccountStatusKey } from '@/lib/bank-accounts/types';

const statusTone: Record<BankAccountStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  active: 'success',
  suspended: 'warning',
  closed: 'danger',
};

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'closed', label: 'Closed' },
];

export default function BankAccountsPage() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const canCreate = useHasPermission('bank_accounts.manage');

  const { data, isLoading, error } = useBankAccounts({
    search: search || undefined,
    status: status || undefined,
    page,
    per_page: 20,
  });

  return (
    <>
      <PageHeader
        title="Bank accounts"
        description="Every Merchant's own designated accounts — where repayments are collected and loans are disbursed from."
        actions={
          canCreate ? (
            <Link href="/bank-accounts/new" className={buttonVariants({ variant: 'primary' })}>
              Add account
            </Link>
          ) : undefined
        }
      />

      <div className="flex flex-wrap gap-3">
        <Field
          label="Search"
          placeholder="Bank, account name or number"
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
          onChange={(event) => {
            setPage(1);
            setStatus(event.target.value);
          }}
          className="w-48"
        />
      </div>

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-4 py-3">Account</th>
                  <th className="px-4 py-3">Purpose</th>
                  <th className="px-4 py-3">Defaults</th>
                  <th className="px-4 py-3">Status</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((account) => (
                  <tr key={account.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link
                        href={`/bank-accounts/${account.id}`}
                        className="font-medium text-brand-700 hover:underline"
                      >
                        {account.bank_name}
                      </Link>
                      <p className="numeric text-xs text-slate-500">
                        {account.account_number_formatted} · {account.account_name}
                      </p>
                    </td>
                    <td className="px-4 py-3 text-slate-700">{account.account_purpose_label}</td>
                    <td className="px-4 py-3">
                      <div className="flex gap-1.5">
                        {account.is_default_collection_account ? (
                          <Badge tone="info">Collection</Badge>
                        ) : null}
                        {account.is_default_disbursement_account ? (
                          <Badge tone="info">Disbursement</Badge>
                        ) : null}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone[account.status]}>{account.status_label}</Badge>
                    </td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="px-4 py-10 text-center text-slate-500">
                      No bank accounts match these filters.
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
