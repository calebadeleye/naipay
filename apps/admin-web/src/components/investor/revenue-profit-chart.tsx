'use client';

import {
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
  CartesianGrid,
} from 'recharts';

import { formatCompactMoney } from '@/lib/format';
import type { RevenueProfitPoint } from '@/lib/investor-dashboard/types';

export function RevenueProfitChart({ data }: { data: RevenueProfitPoint[] }) {
  const points = data.map((point) => ({
    label: point.label,
    revenue: Number.parseFloat(point.revenue),
    net_profit: Number.parseFloat(point.net_profit),
  }));

  return (
    <ResponsiveContainer width="100%" height={280}>
      <LineChart data={points} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke="var(--color-slate-200)" vertical={false} />
        <XAxis
          dataKey="label"
          tick={{ fontSize: 12, fill: 'var(--color-slate-500)' }}
          axisLine={{ stroke: 'var(--color-slate-200)' }}
          tickLine={false}
        />
        <YAxis
          tickFormatter={(value: number) => formatCompactMoney(String(value))}
          tick={{ fontSize: 12, fill: 'var(--color-slate-500)' }}
          axisLine={false}
          tickLine={false}
          width={64}
        />
        <Tooltip
          formatter={(value) => formatCompactMoney(String(value))}
          contentStyle={{
            borderRadius: 8,
            borderColor: 'var(--color-slate-200)',
            fontSize: 13,
          }}
        />
        <Line
          type="monotone"
          dataKey="revenue"
          name="Revenue"
          stroke="var(--color-brand-500)"
          strokeWidth={2}
          dot={false}
        />
        <Line
          type="monotone"
          dataKey="net_profit"
          name="Net profit"
          stroke="var(--color-info)"
          strokeWidth={2}
          dot={false}
        />
      </LineChart>
    </ResponsiveContainer>
  );
}
