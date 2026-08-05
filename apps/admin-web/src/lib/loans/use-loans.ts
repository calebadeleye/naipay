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
