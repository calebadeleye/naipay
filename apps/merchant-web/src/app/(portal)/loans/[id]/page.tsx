'use client';

import { ApiError } from '@naipay/api-client';
import { useParams } from 'next/navigation';
import { useState } from 'react';
import { Download } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Alert } from '@/components/ui/field';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { useLoan, downloadLoanSchedule } from '@/lib/loans/use-loans';
import type { LoanStatusKey } from '@/lib/loans/types';
import { useCurrentMerchant } from '@/lib/auth/use-auth';
import { formatAmountString, formatDate, formatMoney } from '@/lib/format';

const statusTone: Record<LoanStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  pending_approval: 'neutral',
  pending_disbursement: 'warning',
  disbursed: 'success',
  written_off: 'danger',
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="numeric mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

export default function LoanDetailPage() {
  const params = useParams<{ id: string }>();
  const { data: loan, isLoading, error } = useLoan(params.id);
  const { data: merchant } = useCurrentMerchant();

  const [downloading, setDownloading] = useState(false);
  const [downloadError, setDownloadError] = useState<string | null>(null);

  async function handleDownload() {
    if (!loan) return;

    setDownloading(true);
    setDownloadError(null);

    try {
      await downloadLoanSchedule(
        loan.id,
        `repayment-schedule-${merchant?.account?.account_number ?? loan.id}`,
      );
    } catch (err) {
      setDownloadError(err instanceof ApiError ? err.message : 'Could not download the schedule.');
    } finally {
      setDownloading(false);
    }
  }

  return (
    <QueryState isLoading={isLoading} error={error}>
      {loan ? (
        <div className="space-y-6">
          <PageHeader
            title={loan.loan_product?.name ?? 'Loan'}
            description={
              loan.disbursement?.date ? `Taken ${formatDate(loan.disbursement.date)}` : undefined
            }
            actions={
              <div className="flex items-center gap-3">
                <Badge tone={statusTone[loan.status]}>{loan.status_label}</Badge>
                <Button type="button" variant="secondary" size="sm" loading={downloading} onClick={handleDownload}>
                  <Download className="size-4" aria-hidden />
                  Download schedule
                </Button>
              </div>
            }
          />

          {downloadError ? <Alert tone="error">{downloadError}</Alert> : null}

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Terms</h2>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
              <Detail label="Principal" value={formatMoney(loan.terms.principal_amount)} />
              <Detail label="Interest rate" value={`${loan.terms.interest_rate}%`} />
              <Detail label="Tenor" value={loan.terms.tenor} />
              {loan.totals ? <Detail label="Total payable" value={formatMoney(loan.totals.total_payable)} /> : null}
              {loan.outstanding ? (
                <Detail label="Outstanding principal" value={formatMoney(loan.outstanding.principal)} />
              ) : null}
              {loan.disbursement ? (
                <Detail label="Maturity date" value={formatDate(loan.disbursement.maturity_date)} />
              ) : null}
            </div>
          </Card>

          {loan.schedule.length > 0 ? (
            <Card className="overflow-x-auto p-0">
              <h2 className="p-5 pb-0 text-sm font-semibold text-slate-900">Repayment schedule</h2>
              <table className="mt-4 w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                    <th className="px-4 py-3">#</th>
                    <th className="px-4 py-3">Due date</th>
                    <th className="px-4 py-3">Amount</th>
                    <th className="px-4 py-3">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {loan.schedule.map((entry) => (
                    <tr key={entry.id} className="border-b border-slate-100 last:border-0">
                      <td className="px-4 py-3 text-slate-700">{entry.installment_number}</td>
                      <td className="px-4 py-3 text-slate-700">{formatDate(entry.due_date)}</td>
                      <td className="numeric px-4 py-3 text-slate-700">
                        {formatAmountString(
                          (
                            Number(entry.principal_due.amount) +
                            Number(entry.interest_due.amount) +
                            Number(entry.fee_due.amount)
                          ).toFixed(2),
                          entry.principal_due.currency,
                        )}
                      </td>
                      <td className="px-4 py-3">
                        <Badge tone={entry.status === 'paid' ? 'success' : entry.status === 'overdue' ? 'danger' : 'neutral'}>
                          {entry.status_label}
                        </Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <div className="p-4" />
            </Card>
          ) : null}
        </div>
      ) : null}
    </QueryState>
  );
}
