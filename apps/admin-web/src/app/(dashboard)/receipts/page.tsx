'use client';

import Link from 'next/link';
import { useState } from 'react';

import { Card } from '@/components/ui/card';
import { Field } from '@/components/ui/field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { QueryState } from '@/components/ui/query-state';
import { formatDateTime, formatMoney } from '@/lib/format';
import { useReceipts } from '@/lib/receipts/use-receipts';

export default function ReceiptsPage() {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading, error } = useReceipts({
    search: search || undefined,
    page,
    per_page: 20,
  });

  return (
    <>
      <PageHeader
        title="Receipts"
        description="Generated automatically the moment a repayment is approved — never created or edited here."
      />

      <div className="flex flex-wrap gap-3">
        <Field
          label="Search"
          placeholder="Receipt number"
          value={search}
          onChange={(event) => {
            setPage(1);
            setSearch(event.target.value);
          }}
          className="w-72"
        />
      </div>

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-4 py-3">Receipt</th>
                  <th className="px-4 py-3">Merchant</th>
                  <th className="px-4 py-3">Loan</th>
                  <th className="px-4 py-3 text-right">Amount</th>
                  <th className="px-4 py-3">Issued</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((receipt) => (
                  <tr key={receipt.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link
                        href={`/receipts/${receipt.id}`}
                        className="numeric font-medium text-brand-700 hover:underline"
                      >
                        {receipt.receipt_number}
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-slate-700">{receipt.merchant?.full_name ?? '—'}</td>
                    <td className="px-4 py-3 text-slate-700">
                      {receipt.loan ? (
                        <Link href={`/loans/${receipt.loan.id}`} className="text-brand-700 hover:underline">
                          View loan
                        </Link>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="numeric px-4 py-3 text-right text-slate-700">{formatMoney(receipt.amount)}</td>
                    <td className="px-4 py-3 text-slate-500">{formatDateTime(receipt.issued_at)}</td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-4 py-10 text-center text-slate-500">
                      No receipts match these filters.
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
