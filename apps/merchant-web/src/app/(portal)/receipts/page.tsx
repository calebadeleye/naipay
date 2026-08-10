'use client';

import Link from 'next/link';
import { useState } from 'react';

import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { QueryState } from '@/components/ui/query-state';
import { useReceiptList } from '@/lib/receipts/use-receipts';
import { formatDate, formatMoney } from '@/lib/format';

export default function ReceiptsPage() {
  const [page, setPage] = useState(1);
  const { data, isLoading, error } = useReceiptList({ page, per_page: 20 });

  return (
    <>
      <PageHeader title="Receipts" description="A receipt is issued for every repayment Every Merchant records." />

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-4 py-3">Receipt</th>
                  <th className="px-4 py-3">Loan</th>
                  <th className="px-4 py-3">Amount</th>
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
                    <td className="px-4 py-3 text-slate-700">{receipt.loan?.loan_reference ?? '—'}</td>
                    <td className="numeric px-4 py-3 text-slate-700">{formatMoney(receipt.amount)}</td>
                    <td className="px-4 py-3 text-slate-700">{formatDate(receipt.issued_at)}</td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="px-4 py-10 text-center text-slate-500">
                      No receipts yet.
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
