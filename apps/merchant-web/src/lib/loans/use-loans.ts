'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useQuery } from '@tanstack/react-query';

import { api } from '@/lib/auth/api';
import type { Loan } from '@/lib/loans/types';

export function useLoanList(params?: ListQuery) {
  return useQuery({
    queryKey: ['loans', 'list', params ?? {}],
    queryFn: ({ signal }) => api.list<Loan>('/merchant/loans', { signal, query: params }),
  });
}

export function useLoan(id: number | string) {
  return useQuery({
    queryKey: ['loans', 'detail', String(id)],
    queryFn: ({ signal }) => api.get<Loan>(`/merchant/loans/${id}`, { signal }),
    enabled: Boolean(id),
  });
}

/**
 * Downloads the repayment schedule PDF and triggers a browser save — the API
 * client's `download()` returns a Blob, not a URL, since files are streamed
 * through an authenticated endpoint rather than served from a public path.
 */
export async function downloadLoanSchedule(id: number | string, filenameBase: string): Promise<void> {
  const blob = await api.download(`/merchant/loans/${id}/schedule/pdf`);

  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `${filenameBase}.pdf`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}
