'use client';

import { useParams } from 'next/navigation';

import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { useReceipt } from '@/lib/receipts/use-receipts';
import { formatDate, formatMoney } from '@/lib/format';

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="numeric mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

export default function ReceiptDetailPage() {
  const params = useParams<{ id: string }>();
  const { data: receipt, isLoading, error } = useReceipt(params.id);

  return (
    <QueryState isLoading={isLoading} error={error}>
      {receipt ? (
        <div className="space-y-6">
          <PageHeader title={receipt.receipt_number} description={formatDate(receipt.issued_at)} />

          <Card className="max-w-2xl">
            <div className="grid grid-cols-2 gap-4">
              <Detail label="Amount" value={formatMoney(receipt.amount)} />
              <Detail label="Issued" value={formatDate(receipt.issued_at)} />
              <Detail label="Loan" value={receipt.loan?.loan_reference} />
              <Detail label="Business" value={receipt.business?.business_name} />
              {receipt.repayment ? (
                <>
                  <Detail label="Payment date" value={formatDate(receipt.repayment.payment_date)} />
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
