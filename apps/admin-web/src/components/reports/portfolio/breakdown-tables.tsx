'use client';

import type { ReactNode } from 'react';

import { Card } from '@/components/ui/card';
import { formatAmountString, formatNumber } from '@/lib/format';
import type { BreakdownRow, StatusRow } from '@/lib/reports/portfolio-analytics';

function rate(value: number | null): string {
  return value === null ? '—' : `${value.toFixed(1)}%`;
}

function Shell({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
  return (
    <Card className="space-y-0 p-0">
      <div className="border-b border-slate-200 px-5 py-3">
        <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
        {subtitle ? <p className="mt-0.5 text-xs text-slate-500">{subtitle}</p> : null}
      </div>
      <div className="overflow-x-auto">{children}</div>
    </Card>
  );
}

// ── By status ────────────────────────────────────────────────────────────

export function StatusTable({
  rows,
  currency,
  onDrill,
}: {
  rows: StatusRow[];
  currency: string;
  onDrill?: (row: StatusRow) => void;
}) {
  return (
    <Shell title="By status" subtitle="Every state in the loan lifecycle.">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
            <th className="px-5 py-2">Status</th>
            <th className="px-5 py-2 text-right">Loans</th>
            <th className="px-5 py-2 text-right">Outstanding principal</th>
            <th className="px-5 py-2 text-right">Outstanding interest</th>
            <th className="px-5 py-2 text-right">Outstanding fees</th>
            <th className="px-5 py-2 text-right">Total receivable</th>
            <th className="px-5 py-2 text-right">% of portfolio</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr
              key={row.status}
              className={
                onDrill
                  ? 'cursor-pointer border-t border-slate-100 hover:bg-slate-50'
                  : 'border-t border-slate-100'
              }
              onClick={onDrill ? () => onDrill(row) : undefined}
            >
              <td className="px-5 py-2.5 text-slate-700">{row.label}</td>
              <td className="numeric px-5 py-2.5 text-right text-slate-700">{formatNumber(row.loan_count)}</td>
              <td className="numeric px-5 py-2.5 text-right text-slate-700">
                {formatAmountString(row.outstanding_principal, currency)}
              </td>
              <td className="numeric px-5 py-2.5 text-right text-slate-700">
                {formatAmountString(row.outstanding_interest, currency)}
              </td>
              <td className="numeric px-5 py-2.5 text-right text-slate-700">
                {formatAmountString(row.outstanding_fees, currency)}
              </td>
              <td className="numeric px-5 py-2.5 text-right font-medium text-slate-900">
                {formatAmountString(row.total_receivable, currency)}
              </td>
              <td className="numeric px-5 py-2.5 text-right text-slate-500">
                {rate(row.percentage_of_portfolio)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </Shell>
  );
}

// ── By product / officer / branch ───────────────────────────────────────

export function BreakdownTable({
  title,
  subtitle,
  dimensionHeading,
  rows,
  currency,
  emptyLabel,
  onDrill,
}: {
  title: string;
  subtitle?: string;
  dimensionHeading: string;
  rows: BreakdownRow[];
  currency: string;
  emptyLabel: string;
  onDrill?: (row: BreakdownRow) => void;
}) {
  return (
    <Shell title={title} subtitle={subtitle}>
      {rows.length === 0 ? (
        <p className="px-5 py-6 text-sm text-slate-500">{emptyLabel}</p>
      ) : (
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
              <th className="px-5 py-2">{dimensionHeading}</th>
              <th className="px-5 py-2 text-right">Loans</th>
              <th className="px-5 py-2 text-right">Borrowers</th>
              <th className="px-5 py-2 text-right">Disbursed</th>
              <th className="px-5 py-2 text-right">Outstanding</th>
              <th className="px-5 py-2 text-right">Receivable</th>
              <th className="px-5 py-2 text-right">Collected</th>
              <th className="px-5 py-2 text-right">Overdue</th>
              <th className="px-5 py-2 text-right">PAR 30</th>
              <th className="px-5 py-2 text-right">Coll. rate</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr
                key={row.id ?? row.name}
                className={
                  onDrill && row.id !== null
                    ? 'cursor-pointer border-t border-slate-100 hover:bg-slate-50'
                    : 'border-t border-slate-100'
                }
                onClick={onDrill && row.id !== null ? () => onDrill(row) : undefined}
              >
                <td className="px-5 py-2.5 font-medium text-slate-800">{row.name}</td>
                <td className="numeric px-5 py-2.5 text-right text-slate-700">{formatNumber(row.loans)}</td>
                <td className="numeric px-5 py-2.5 text-right text-slate-700">{formatNumber(row.borrowers)}</td>
                <td className="numeric px-5 py-2.5 text-right text-slate-700">
                  {formatAmountString(row.total_disbursed, currency)}
                </td>
                <td className="numeric px-5 py-2.5 text-right text-slate-700">
                  {formatAmountString(row.outstanding_principal, currency)}
                </td>
                <td className="numeric px-5 py-2.5 text-right text-slate-700">
                  {formatAmountString(row.outstanding_receivable, currency)}
                </td>
                <td className="numeric px-5 py-2.5 text-right text-slate-700">
                  {formatAmountString(row.collected, currency)}
                </td>
                <td className="numeric px-5 py-2.5 text-right text-slate-700">
                  {formatAmountString(row.overdue, currency)}
                </td>
                <td className="numeric px-5 py-2.5 text-right text-slate-700">
                  {formatAmountString(row.par30, currency)}
                </td>
                <td className="numeric px-5 py-2.5 text-right text-slate-500">{rate(row.collection_rate)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Shell>
  );
}
