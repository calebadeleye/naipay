'use client';

import type { PaginationMeta } from '@naipay/shared-types';
import { ChevronLeft, ChevronRight } from 'lucide-react';

import { Button } from '@/components/ui/button';

export function Pagination({
  meta,
  page,
  onPageChange,
}: {
  meta: PaginationMeta;
  page: number;
  onPageChange: (page: number) => void;
}) {
  if (meta.last_page <= 1) {
    return null;
  }

  return (
    <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3">
      <p className="text-sm text-slate-500">
        {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
      </p>

      <div className="flex items-center gap-2">
        <Button
          type="button"
          variant="secondary"
          size="sm"
          disabled={page <= 1}
          onClick={() => onPageChange(page - 1)}
        >
          <ChevronLeft className="size-4" aria-hidden />
          Previous
        </Button>
        <span className="numeric text-sm text-slate-600">
          Page {meta.current_page} of {meta.last_page}
        </span>
        <Button
          type="button"
          variant="secondary"
          size="sm"
          disabled={!meta.has_more_pages}
          onClick={() => onPageChange(page + 1)}
        >
          Next
          <ChevronRight className="size-4" aria-hidden />
        </Button>
      </div>
    </div>
  );
}
