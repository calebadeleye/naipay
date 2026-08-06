'use client';

import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';

import { formatAmountString } from '@/lib/format';
import type { PortfolioAllocationSlice } from '@/lib/investor-dashboard/types';

const SLICE_COLORS = [
  'var(--color-brand-600)',
  'var(--color-brand-400)',
  'var(--color-info)',
  'var(--color-warning)',
  'var(--color-brand-800)',
  'var(--color-brand-200)',
];

export function PortfolioAllocationChart({ data }: { data: PortfolioAllocationSlice[] }) {
  const slices = data.map((slice) => ({
    name: slice.name,
    value: Number.parseFloat(slice.outstanding_principal),
    percentage: slice.percentage,
  }));

  return (
    <div className="flex flex-col items-center gap-4 sm:flex-row">
      <div className="h-48 w-48 shrink-0">
        <ResponsiveContainer width="100%" height="100%">
          <PieChart>
            <Pie data={slices} dataKey="value" nameKey="name" innerRadius={55} outerRadius={80} paddingAngle={2}>
              {slices.map((slice, index) => (
                <Cell key={slice.name} fill={SLICE_COLORS[index % SLICE_COLORS.length]} />
              ))}
            </Pie>
            <Tooltip formatter={(value) => formatAmountString(String(value))} />
          </PieChart>
        </ResponsiveContainer>
      </div>

      <ul className="w-full space-y-2">
        {slices.map((slice, index) => (
          <li key={slice.name} className="flex items-center justify-between gap-3 text-sm">
            <span className="flex items-center gap-2 text-slate-600">
              <span
                className="size-2.5 shrink-0 rounded-full"
                style={{ backgroundColor: SLICE_COLORS[index % SLICE_COLORS.length] }}
                aria-hidden
              />
              {slice.name}
            </span>
            <span className="numeric font-medium text-slate-900">{slice.percentage}%</span>
          </li>
        ))}

        {slices.length === 0 ? <li className="text-sm text-slate-500">No disbursed loans yet.</li> : null}
      </ul>
    </div>
  );
}
