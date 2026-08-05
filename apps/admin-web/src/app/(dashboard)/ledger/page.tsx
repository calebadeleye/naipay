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
import { useJournalTransactions } from '@/lib/ledger/use-ledger';
import type { JournalTransactionStatusKey } from '@/lib/ledger/types';

const statusTone: Record<JournalTransactionStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  posted: 'success',
  reversed: 'neutral',
};

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'posted', label: 'Posted' },
  { value: 'reversed', label: 'Reversed' },
];

export default function LedgerPage() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading, error } = useJournalTransactions({
    search: search || undefined,
    status: status || undefined,
    page,
    per_page: 20,
  });

  return (
    <>
      <PageHeader
        title="Ledger"
        description="A raw view of the journal — enough to verify a posting or trace one back to what caused it."
        actions={
          <Link href="/ledger/accounts" className={buttonVariants({ variant: 'secondary' })}>
            Chart of accounts
          </Link>
        }
      />

      <div className="flex flex-wrap gap-3">
        <Field
          label="Search"
          placeholder="Reference or description"
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
                  <th className="px-4 py-3">Reference</th>
                  <th className="px-4 py-3">Type</th>
                  <th className="px-4 py-3">Description</th>
                  <th className="px-4 py-3">Posting date</th>
                  <th className="px-4 py-3">Status</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((transaction) => (
                  <tr key={transaction.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link
                        href={`/ledger/${transaction.id}`}
                        className="numeric font-medium text-brand-700 hover:underline"
                      >
                        {transaction.transaction_reference}
                      </Link>
                      {transaction.is_reversal ? (
                        <p className="text-xs text-slate-500">Reversal</p>
                      ) : null}
                    </td>
                    <td className="px-4 py-3 text-slate-700">{transaction.transaction_type}</td>
                    <td className="px-4 py-3 text-slate-700">{transaction.description ?? '—'}</td>
                    <td className="px-4 py-3 text-slate-500">{formatDate(transaction.posting_date)}</td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone[transaction.status]}>{transaction.status_label}</Badge>
                    </td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-4 py-10 text-center text-slate-500">
                      No journal transactions match these filters.
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
