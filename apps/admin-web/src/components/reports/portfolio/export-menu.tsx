'use client';

import { ChevronDown, Download, Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { useHasPermission } from '@/lib/auth/use-permission';
import {
  type PortfolioExportSection,
  type PortfolioFilterState,
  usePortfolioAnalyticsExport,
} from '@/lib/reports/portfolio-analytics';

const SECTIONS: { value: PortfolioExportSection; label: string }[] = [
  { value: 'summary', label: 'Full summary' },
  { value: 'status', label: 'By status' },
  { value: 'products', label: 'By product' },
  { value: 'officers', label: 'Loan officer performance' },
  { value: 'branches', label: 'By branch' },
  { value: 'aging', label: 'Portfolio aging' },
  { value: 'risk', label: 'Portfolio at risk' },
];

export function PortfolioExportMenu({ filters }: { filters: PortfolioFilterState }) {
  const canExport = useHasPermission('reports.export');
  const exporter = usePortfolioAnalyticsExport(filters);
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    function onClick(event: MouseEvent) {
      if (ref.current && !ref.current.contains(event.target as Node)) setOpen(false);
    }
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, [open]);

  if (!canExport) return null;

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        disabled={exporter.isPending}
        className="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50 disabled:opacity-60"
      >
        {exporter.isPending ? (
          <Loader2 className="size-4 animate-spin" aria-hidden />
        ) : (
          <Download className="size-4" aria-hidden />
        )}
        Export CSV
        <ChevronDown className="size-3.5 text-slate-400" aria-hidden />
      </button>

      {open ? (
        <div className="absolute right-0 z-20 mt-1 w-56 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
          {SECTIONS.map((section) => (
            <button
              key={section.value}
              type="button"
              onClick={() => {
                setOpen(false);
                exporter.mutate(section.value);
              }}
              className="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
            >
              {section.label}
            </button>
          ))}
        </div>
      ) : null}

      {exporter.isError ? (
        <p className="absolute right-0 mt-1 text-xs text-danger">Export failed. Try again.</p>
      ) : null}
    </div>
  );
}
