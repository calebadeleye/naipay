'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type { Loan } from '@/lib/loans/types';

export const loanKeys = {
  list: (params?: ListQuery) => ['loans', 'list', params ?? {}] as const,
  detail: (id: number | string) => ['loans', 'detail', String(id)] as const,
};

export function useLoans(params?: ListQuery) {
  return useQuery({
    queryKey: loanKeys.list(params),
    queryFn: ({ signal }) => api.list<Loan>('/admin/loans', { signal, query: params }),
  });
}

export function useLoan(id: number | string) {
  return useQuery({
    queryKey: loanKeys.detail(id),
    queryFn: ({ signal }) => api.get<Loan>(`/admin/loans/${id}`, { signal }),
    enabled: Boolean(id),
  });
}

export function useApproveLoan(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => api.post<Loan>(`/admin/loans/${id}/approve`),
    onSuccess: (loan) => {
      queryClient.setQueryData(loanKeys.detail(id), loan);
      void queryClient.invalidateQueries({ queryKey: ['loans', 'list'] });
    },
  });
}

export function useDisburseLoan(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { bank_account_id: number; disbursement_date?: string }) =>
      api.post<Loan>(`/admin/loans/${id}/disburse`, input),
    onSuccess: (loan) => {
      queryClient.setQueryData(loanKeys.detail(id), loan);
      void queryClient.invalidateQueries({ queryKey: ['loans', 'list'] });
    },
  });
}

export function useWriteOffLoan(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { reason: string }) => api.post<Loan>(`/admin/loans/${id}/write-off`, input),
    onSuccess: (loan) => {
      queryClient.setQueryData(loanKeys.detail(id), loan);
      void queryClient.invalidateQueries({ queryKey: ['loans', 'list'] });
    },
  });
}

/**
 * Shifts the repayment schedule to new dates (public holiday, or a merchant's
 * request). Amounts and interest are untouched — only the dates move.
 */
export function useRescheduleLoan(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { next_due_date: string; reason: string }) =>
      api.post<Loan>(`/admin/loans/${id}/reschedule`, input),
    onSuccess: (loan) => {
      queryClient.setQueryData(loanKeys.detail(id), loan);
      void queryClient.invalidateQueries({ queryKey: ['loans', 'list'] });
    },
  });
}

/**
 * Downloads the repayment schedule PDF and hands it straight to the
 * browser's save flow — the API client's `download()` already attaches the
 * bearer token a plain `<a href>` couldn't.
 */
export function useDownloadLoanSchedulePdf(id: number | string, filenameBase: string) {
  return useMutation({
    mutationFn: () => api.download(`/admin/loans/${id}/schedule/pdf`),
    onSuccess: (blob) => {
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `${filenameBase}.pdf`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    },
  });
}

export function useEmailLoanSchedule(id: number | string) {
  return useMutation({
    mutationFn: () => api.post<null>(`/admin/loans/${id}/schedule/email`),
  });
}
