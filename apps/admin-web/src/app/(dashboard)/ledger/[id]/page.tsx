'use client';

import { ApiError } from '@naipay/api-client';
import Link from 'next/link';
import { useParams, useRouter } from 'next/navigation';
import { useState } from 'react';

import { Alert } from '@/components/ui/field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { TextareaField } from '@/components/ui/textarea';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { useJournalTransaction, useReverseJournalTransaction } from '@/lib/ledger/use-ledger';
import type { JournalTransactionStatusKey } from '@/lib/ledger/types';

const statusTone: Record<JournalTransactionStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  posted: 'success',
  reversed: 'neutral',
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

function ReverseAction({ transactionId }: { transactionId: number }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const reverseTransaction = useReverseJournalTransaction(transactionId);
  const error = reverseTransaction.error instanceof ApiError ? reverseTransaction.error : null;

  async function handleConfirm() {
    try {
      const reversal = await reverseTransaction.mutateAsync({ reason });
      router.push(`/ledger/${reversal.id}`);
    } catch {
      // Surfaced via `error` above, rendered from the mutation state.
    }
  }

  if (!open) {
    return (
      <Button type="button" variant="destructive" onClick={() => setOpen(true)}>
        Reverse
      </Button>
    );
  }

  return (
    <div className="w-full max-w-md space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4">
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}

      <TextareaField
        label="Reason for reversal"
        value={reason}
        onChange={(event) => setReason(event.target.value)}
        autoFocus
        hint="At least 10 characters. Posts a compensating entry — the original stays in the journal permanently."
      />

      <div className="flex gap-2">
        <Button type="button" variant="destructive" loading={reverseTransaction.isPending} onClick={handleConfirm}>
          Confirm reversal
        </Button>
        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

export default function LedgerTransactionDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: transaction, isLoading, error } = useJournalTransaction(id);

  return (
    <QueryState isLoading={isLoading} error={error}>
      {transaction ? (
        <div className="space-y-6">
          <PageHeader
            title={transaction.transaction_reference}
            description={transaction.description ?? transaction.transaction_type}
            actions={<Badge tone={statusTone[transaction.status]}>{transaction.status_label}</Badge>}
          />

          {transaction.status === 'posted' && !transaction.is_reversal ? (
            <Card>
              <h2 className="mb-4 text-sm font-semibold text-slate-900">Workflow</h2>
              <ReverseAction transactionId={transaction.id} />
              <p className="mt-3 text-xs text-slate-500">
                Posts a second, independent, balanced transaction — never an edit to this one.
              </p>
            </Card>
          ) : null}

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Transaction</h2>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
              <Detail label="Type" value={transaction.transaction_type} />
              <Detail label="Source" value={transaction.source_type ? `${transaction.source_type} #${transaction.source_id}` : null} />
              <Detail label="Currency" value={transaction.currency} />
              <Detail label="Transaction date" value={formatDate(transaction.transaction_date)} />
              <Detail label="Posting date" value={formatDate(transaction.posting_date)} />
              <Detail label="Created by" value={transaction.created_by} />
              {transaction.reversal_of_id ? (
                <Detail
                  label="Reversal of"
                  value={
                    <Link href={`/ledger/${transaction.reversal_of_id}`} className="text-brand-700 hover:underline">
                      View original
                    </Link>
                  }
                />
              ) : null}
              {transaction.reversed_at ? (
                <Detail label="Reversed at" value={formatDateTime(transaction.reversed_at)} />
              ) : null}
            </div>
          </Card>

          {transaction.entries ? (
            <Card className="overflow-x-auto p-0">
              <h2 className="px-4 pt-4 text-sm font-semibold text-slate-900">Entries</h2>
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                    <th className="px-4 py-3">Account</th>
                    <th className="px-4 py-3">Description</th>
                    <th className="px-4 py-3 text-right">Debit</th>
                    <th className="px-4 py-3 text-right">Credit</th>
                  </tr>
                </thead>
                <tbody>
                  {transaction.entries.map((entry, index) => (
                    <tr key={index} className="border-b border-slate-100 last:border-0">
                      <td className="px-4 py-3">
                        <p className="font-medium text-slate-900">{entry.account_name}</p>
                        <p className="numeric text-xs text-slate-500">{entry.account_code}</p>
                      </td>
                      <td className="px-4 py-3 text-slate-700">{entry.description ?? '—'}</td>
                      <td className="numeric px-4 py-3 text-right text-slate-700">
                        {formatMoney(entry.debit_amount)}
                      </td>
                      <td className="numeric px-4 py-3 text-right text-slate-700">
                        {formatMoney(entry.credit_amount)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          ) : null}

          <p className="text-xs text-slate-400">Created {formatDateTime(transaction.created_at)}</p>
        </div>
      ) : null}
    </QueryState>
  );
}
