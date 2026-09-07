'use client';

import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { Card } from '@/components/ui/card';
import { formatAmountString, formatCompactMoney, formatNumber } from '@/lib/format';
import type { AgingBucketRow, ParBand } from '@/lib/reports/portfolio-analytics';

function rate(value: number | null): string {
  return value === null ? '—' : `${value.toFixed(1)}%`;
}

// Green → red as the arrears age. Bucket order is fixed by config, so index
// maps to severity.
const AGING_COLORS = [
  'var(--color-success)',
  'var(--color-warning)',
  '#f59e0b',
  '#f97316',
  '#ef4444',
  'var(--color-danger)',
];

interface RiskPanelProps {
  bands: ParBand[];
  currency: string;
  onDrill?: (band: ParBand) => void;
}

export function RiskPanel({ bands, currency, onDrill }: RiskPanelProps) {
  return (
    <Card className="space-y-4">
      <div>
        <h2 className="text-sm font-semibold text-slate-900">Portfolio at risk</h2>
        <p className="mt-0.5 text-xs text-slate-500">
          Outstanding principal of loans whose earliest unpaid instalment is at least N days overdue.
        </p>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        {bands.map((band) => {
          const inner = (
            <>
              <p className="text-xs font-medium text-slate-500">PAR {band.threshold_days}</p>
              <p className="numeric mt-1 text-lg font-semibold text-slate-900">
                {formatAmountString(band.at_risk_amount, currency)}
              </p>
              <p className="mt-0.5 text-xs text-slate-500">
                {rate(band.percentage_of_outstanding)} of portfolio · {formatNumber(band.loan_count)} loan
                {band.loan_count === 1 ? '' : 's'}
              </p>
            </>
          );

          return onDrill ? (
            <button
              key={band.threshold_days}
              type="button"
              onClick={() => onDrill(band)}
              className="rounded-xl border border-slate-200/70 bg-white/50 p-3 text-left transition hover:ring-2 hover:ring-brand-200"
            >
              {inner}
            </button>
          ) : (
            <div key={band.threshold_days} className="rounded-xl border border-slate-200/70 bg-white/50 p-3">
              {inner}
            </div>
          );
        })}
      </div>
    </Card>
  );
}

interface AgingPanelProps {
  buckets: AgingBucketRow[];
  totalLoans: number;
  currency: string;
  onDrill?: (bucket: AgingBucketRow) => void;
}

export function AgingPanel({ buckets, totalLoans, currency, onDrill }: AgingPanelProps) {
  const chartData = buckets.map((bucket) => ({
    label: bucket.label,
    principal: Number.parseFloat(bucket.outstanding_principal),
  }));

  return (
    <Card className="space-y-4 p-0">
      <div className="px-5 pt-5">
        <h2 className="text-sm font-semibold text-slate-900">Portfolio aging</h2>
        <p className="mt-0.5 text-xs text-slate-500">
          {formatNumber(totalLoans)} active loan{totalLoans === 1 ? '' : 's'} by days past due.
        </p>
      </div>

      <div className="px-3">
        <ResponsiveContainer width="100%" height={220}>
          <BarChart data={chartData} margin={{ top: 4, right: 8, bottom: 0, left: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--color-slate-200)" vertical={false} />
            <XAxis
              dataKey="label"
              tick={{ fontSize: 11, fill: 'var(--color-slate-500)' }}
              axisLine={{ stroke: 'var(--color-slate-200)' }}
              tickLine={false}
              interval={0}
            />
            <YAxis
              tickFormatter={(value: number) => formatCompactMoney(String(value), currency)}
              tick={{ fontSize: 11, fill: 'var(--color-slate-500)' }}
              axisLine={false}
              tickLine={false}
              width={60}
            />
            <Tooltip
              cursor={{ fill: 'var(--color-slate-100)' }}
              formatter={(value) => [formatAmountString(String(value), currency), 'Outstanding principal']}
              contentStyle={{ borderRadius: 8, borderColor: 'var(--color-slate-200)', fontSize: 13 }}
            />
            <Bar dataKey="principal" radius={[4, 4, 0, 0]}>
              {chartData.map((entry, index) => (
                <Cell key={entry.label} fill={AGING_COLORS[index] ?? 'var(--color-danger)'} />
              ))}
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-y border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
              <th className="px-5 py-2">Bucket</th>
              <th className="px-5 py-2 text-right">Loans</th>
              <th className="px-5 py-2 text-right">Outstanding principal</th>
              <th className="px-5 py-2 text-right">Outstanding receivable</th>
              <th className="px-5 py-2 text-right">% of portfolio</th>
            </tr>
          </thead>
          <tbody>
            {buckets.map((bucket) => (
              <tr
                key={bucket.label}
                className={onDrill ? 'cursor-pointer border-b border-slate-100 hover:bg-slate-50' : 'border-b border-slate-100'}
                onClick={onDrill ? () => onDrill(bucket) : undefined}
              >
                <td className="px-5 py-2.5 text-slate-700">{bucket.label}</td>
                <td className="numeric px-5 py-2.5 text-right text-slate-700">{formatNumber(bucket.loan_count)}</td>
                <td className="numeric px-5 py-2.5 text-right text-slate-700">
                  {formatAmountString(bucket.outstanding_principal, currency)}
                </td>
                <td className="numeric px-5 py-2.5 text-right text-slate-700">
                  {formatAmountString(bucket.outstanding_receivable, currency)}
                </td>
                <td className="numeric px-5 py-2.5 text-right text-slate-500">
                  {rate(bucket.percentage_of_portfolio)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  );
}
