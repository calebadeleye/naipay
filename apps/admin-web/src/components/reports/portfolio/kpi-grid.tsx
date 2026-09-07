'use client';

import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import Link from 'next/link';
import type { ReactNode } from 'react';

import { Card } from '@/components/ui/card';
import { cn } from '@/lib/cn';
import { formatAmountString, formatNumber } from '@/lib/format';
import type { KpiFigure } from '@/lib/reports/portfolio-analytics';

function renderValue(figure: KpiFigure, currency: string): string {
  if (figure.format === 'money') {
    return formatAmountString(figure.value, currency);
  }

  return formatNumber(Number.parseInt(figure.value, 10));
}

function ChangeLine({ figure, currency }: { figure: KpiFigure; currency: string }) {
  if (!figure.comparison_available || figure.change_pct === null) {
    return <p className="mt-1 text-xs text-slate-400">No comparable prior period</p>;
  }

  const toneClass =
    figure.tone === 'positive'
      ? 'text-success'
      : figure.tone === 'negative'
        ? 'text-danger'
        : 'text-slate-500';

  const Icon = figure.direction === 'up' ? ArrowUpRight : figure.direction === 'down' ? ArrowDownRight : Minus;
  const magnitude = Math.abs(figure.change_pct).toFixed(1);
  const previous =
    figure.previous === null
      ? null
      : figure.format === 'money'
        ? formatAmountString(figure.previous, currency)
        : formatNumber(Number.parseInt(figure.previous, 10));

  return (
    <p className={cn('mt-1 flex items-center gap-1 text-xs font-medium', toneClass)}>
      <Icon className="size-3.5 shrink-0" aria-hidden />
      <span>
        {figure.change_pct > 0 ? '+' : figure.change_pct < 0 ? '−' : ''}
        {magnitude}%
      </span>
      {previous ? <span className="font-normal text-slate-400">vs {previous}</span> : null}
    </p>
  );
}

interface KpiCardProps {
  label: string;
  figure: KpiFigure;
  currency: string;
  definition?: string;
  href?: string;
  onClick?: () => void;
}

export function KpiCard({ label, figure, currency, definition, href, onClick }: KpiCardProps) {
  const body = (
    <>
      <p className="text-sm font-medium text-slate-500">{label}</p>
      <p className="numeric mt-1.5 text-2xl font-semibold text-slate-900">{renderValue(figure, currency)}</p>
      <ChangeLine figure={figure} currency={currency} />
      {definition ? <p className="mt-2 text-xs leading-snug text-slate-400">{definition}</p> : null}
    </>
  );

  if (href) {
    return (
      <Link
        href={href}
        className="glass-surface block rounded-2xl p-5 transition hover:ring-2 hover:ring-brand-200"
      >
        {body}
      </Link>
    );
  }

  if (onClick) {
    return (
      <button
        type="button"
        onClick={onClick}
        className="glass-surface block rounded-2xl p-5 text-left transition hover:ring-2 hover:ring-brand-200"
      >
        {body}
      </button>
    );
  }

  return <Card>{body}</Card>;
}

export function KpiGrid({ children }: { children: ReactNode }) {
  return <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">{children}</div>;
}
