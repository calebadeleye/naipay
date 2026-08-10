import type { ReactNode } from 'react';

import { cn } from '@/lib/cn';

export function Card({ className, children }: { className?: string; children: ReactNode }) {
  return <div className={cn('glass-surface rounded-2xl p-5', className)}>{children}</div>;
}

interface StatCardProps {
  label: string;
  value: ReactNode;
  hint?: ReactNode;
  tone?: 'default' | 'success' | 'warning' | 'danger';
}

const toneClasses: Record<NonNullable<StatCardProps['tone']>, string> = {
  default: 'text-slate-900',
  success: 'text-success',
  warning: 'text-warning',
  danger: 'text-danger',
};

export function StatCard({ label, value, hint, tone = 'default' }: StatCardProps) {
  return (
    <Card>
      <p className="text-sm font-medium text-slate-500">{label}</p>
      <p className={cn('numeric mt-1.5 text-2xl font-semibold', toneClasses[tone])}>{value}</p>
      {hint ? <p className="mt-1 text-xs text-slate-500">{hint}</p> : null}
    </Card>
  );
}
