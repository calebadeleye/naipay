'use client';

import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { Card } from '@/components/ui/card';
import { formatAmountString, formatCompactMoney } from '@/lib/format';
import type { FlowPoint } from '@/lib/reports/portfolio-analytics';

const GRANULARITY_LABEL: Record<string, string> = {
  day: 'daily',
  week: 'weekly',
  month: 'monthly',
};

interface Props {
  points: FlowPoint[];
  granularity: 'day' | 'week' | 'month';
  currency: string;
}

export function DisbursementCollectionChart({ points, granularity, currency }: Props) {
  const data = points.map((point) => ({
    label: point.label,
    disbursed: Number.parseFloat(point.disbursed),
    collected: Number.parseFloat(point.collected),
  }));

  return (
    <Card className="space-y-3">
      <div>
        <h2 className="text-sm font-semibold text-slate-900">Disbursement vs collection</h2>
        <p className="mt-0.5 text-xs text-slate-500">
          Money lent out against money collected back, {GRANULARITY_LABEL[granularity]} over the selected period.
        </p>
      </div>

      {data.length === 0 ? (
        <p className="py-10 text-center text-sm text-slate-500">No activity in this period.</p>
      ) : (
        <ResponsiveContainer width="100%" height={280}>
          <BarChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }} barGap={2}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--color-slate-200)" vertical={false} />
            <XAxis
              dataKey="label"
              tick={{ fontSize: 11, fill: 'var(--color-slate-500)' }}
              axisLine={{ stroke: 'var(--color-slate-200)' }}
              tickLine={false}
              minTickGap={16}
            />
            <YAxis
              tickFormatter={(value: number) => formatCompactMoney(String(value), currency)}
              tick={{ fontSize: 11, fill: 'var(--color-slate-500)' }}
              axisLine={false}
              tickLine={false}
              width={64}
            />
            <Tooltip
              cursor={{ fill: 'var(--color-slate-100)' }}
              formatter={(value, name) => [
                formatAmountString(String(value), currency),
                name === 'disbursed' ? 'Disbursed' : 'Collected',
              ]}
              contentStyle={{ borderRadius: 8, borderColor: 'var(--color-slate-200)', fontSize: 13 }}
            />
            <Legend
              formatter={(value) => (value === 'disbursed' ? 'Disbursed' : 'Collected')}
              wrapperStyle={{ fontSize: 12 }}
            />
            <Bar dataKey="disbursed" fill="var(--color-brand-500)" radius={[3, 3, 0, 0]} />
            <Bar dataKey="collected" fill="var(--color-success)" radius={[3, 3, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      )}
    </Card>
  );
}
