'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';

import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { formatDateTime, formatMoney } from '@/lib/format';
import { useReceipt } from '@/lib/receipts/use-receipts';

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

export default function ReceiptDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: receipt, isLoading, error } = useReceipt(id);

  return (
    <QueryState isLoading={isLoading} error={error}>
      {receipt ? (
        <div className="space-y-6">
          <PageHeader title={receipt.receipt_number} description={formatDateTime(receipt.issued_at)} />

          <Card className="max-w-2xl">
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Receipt</h2>
            <div className="grid grid-cols-2 gap-4">
              <Detail label="Amount" value={formatMoney(receipt.amount)} />
              <Detail label="Issued at" value={formatDateTime(receipt.issued_at)} />
              <Detail
                label="Merchant"
                value={
                  receipt.merchant ? (
                    <Link href={`/merchants/${receipt.merchant.id}`} className="text-brand-700 hover:underline">
                      {receipt.merchant.full_name}
                    </Link>
                  ) : null
                }
              />
              <Detail label="Business" value={receipt.business?.business_name} />
              <Detail
                label="Loan"
                value={
                  receipt.loan ? (
                    <Link href={`/loans/${receipt.loan.id}`} className="text-brand-700 hover:underline">
                      {receipt.loan.loan_reference}
                    </Link>
                  ) : null
                }
              />
              <Detail
                label="Repayment"
                value={
                  receipt.repayment ? (
                    <Link href={`/repayments/${receipt.repayment.id}`} className="text-brand-700 hover:underline">
                      {receipt.repayment.repayment_reference}
                    </Link>
                  ) : null
                }
              />
              {receipt.repayment ? (
                <>
                  <Detail label="Payment date" value={receipt.repayment.payment_date} />
                  <Detail label="Payment method" value={receipt.repayment.payment_method} />
                </>
              ) : null}
            </div>
          </Card>
        </div>
      ) : null}
    </QueryState>
  );
}
