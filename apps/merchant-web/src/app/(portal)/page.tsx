'use client';

import Link from 'next/link';

import { Card, StatCard } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { buttonVariants } from '@/components/ui/button';
import { useCurrentMerchant } from '@/lib/auth/use-auth';
import { useRepaymentSummary } from '@/lib/repayment-summary/use-repayment-summary';
import { formatDate, formatMoney } from '@/lib/format';

export default function DashboardPage() {
  const { data: merchant } = useCurrentMerchant();
  const { data: summary, isLoading, error } = useRepaymentSummary();

  return (
    <>
      <PageHeader
        title={`Welcome back${merchant ? `, ${merchant.first_name}` : ''}`}
        description="Here's where things stand with your account."
        actions={
          <Link href="/loan-applications/new" className={buttonVariants({ variant: 'primary' })}>
            Apply for a loan
          </Link>
        }
      />

      <QueryState isLoading={isLoading} error={error}>
        {summary ? (
          <div className="space-y-6">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <StatCard
                label="Outstanding balance"
                value={formatMoney(summary.outstanding_balance)}
                tone={summary.outstanding_balance.minor_units > 0 ? 'warning' : 'default'}
              />
              <StatCard label="Active loans" value={summary.active_loan_count} />
              <StatCard
                label="Next payment due"
                value={summary.next_due ? formatDate(summary.next_due.due_date) : 'None'}
                hint={summary.next_due ? formatMoney(summary.next_due.amount) : undefined}
              />
            </div>

            {summary.next_due ? (
              <Card>
                <h2 className="mb-1 text-sm font-semibold text-slate-900">Your next payment</h2>
                <p className="text-sm text-slate-600">
                  {formatMoney(summary.next_due.amount)} is due on {formatDate(summary.next_due.due_date)} for
                  loan{' '}
                  <Link href={`/loans/${summary.next_due.loan_id}`} className="text-brand-700 hover:underline">
                    {summary.next_due.loan_reference}
                  </Link>
                  .
                </p>

                {summary.pay_into ? (
                  <div className="mt-4 rounded-md border border-slate-200 bg-slate-50 px-4 py-3">
                    <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">Pay into</p>
                    <p className="mt-1 text-sm font-semibold text-slate-900">{summary.pay_into.bank_name}</p>
                    <p className="numeric text-sm text-slate-700">{summary.pay_into.account_number}</p>
                    <p className="text-sm text-slate-600">{summary.pay_into.account_name}</p>
                    <p className="mt-2 text-xs text-slate-500">
                      Every Merchant confirms payments manually against the bank statement — allow up to one
                      business day for your balance to update after you pay.
                    </p>
                  </div>
                ) : null}
              </Card>
            ) : (
              <Card>
                <p className="text-sm text-slate-600">
                  {summary.active_loan_count > 0
                    ? "You're all caught up — nothing is due right now."
                    : 'You have no active loans yet.'}
                </p>
              </Card>
            )}
          </div>
        ) : null}
      </QueryState>
    </>
  );
}
