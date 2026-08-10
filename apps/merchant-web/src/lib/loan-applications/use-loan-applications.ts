'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/auth/api';
import type { LoanApplication, LoanApplicationFormInput } from '@/lib/loan-applications/types';

export function useLoanApplicationList(params?: ListQuery) {
  return useQuery({
    queryKey: ['loan-applications', 'list', params ?? {}],
    queryFn: ({ signal }) => api.list<LoanApplication>('/merchant/loan-applications', { signal, query: params }),
  });
}

export function useLoanApplication(id: number | string) {
  return useQuery({
    queryKey: ['loan-applications', 'detail', String(id)],
    queryFn: ({ signal }) => api.get<LoanApplication>(`/merchant/loan-applications/${id}`, { signal }),
    enabled: Boolean(id),
  });
}

export function useCreateLoanApplication() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: LoanApplicationFormInput) =>
      api.post<LoanApplication>('/merchant/loan-applications', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['loan-applications', 'list'] });
    },
  });
}

export function useWithdrawLoanApplication(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { reason: string }) =>
      api.post<LoanApplication>(`/merchant/loan-applications/${id}/withdraw`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['loan-applications', 'detail', String(id)] });
      void queryClient.invalidateQueries({ queryKey: ['loan-applications', 'list'] });
    },
  });
}
