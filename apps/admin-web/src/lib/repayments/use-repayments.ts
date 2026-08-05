'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type { Repayment, RepaymentFormInput } from '@/lib/repayments/types';

export const repaymentKeys = {
  list: (params?: ListQuery) => ['repayments', 'list', params ?? {}] as const,
  detail: (id: number | string) => ['repayments', 'detail', String(id)] as const,
};

export function useRepayments(params?: ListQuery) {
  return useQuery({
    queryKey: repaymentKeys.list(params),
    queryFn: ({ signal }) => api.list<Repayment>('/admin/repayments', { signal, query: params }),
  });
}

export function useRepayment(id: number | string) {
  return useQuery({
    queryKey: repaymentKeys.detail(id),
    queryFn: ({ signal }) => api.get<Repayment>(`/admin/repayments/${id}`, { signal }),
    enabled: Boolean(id),
  });
}

export function useCreateRepayment() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: RepaymentFormInput) => api.post<Repayment>('/admin/repayments', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['repayments', 'list'] });
    },
  });
}

function useWorkflowAction(id: number | string, action: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input?: Record<string, unknown>) => api.post<Repayment>(`/admin/repayments/${id}/${action}`, input),
    onSuccess: (repayment) => {
      queryClient.setQueryData(repaymentKeys.detail(id), repayment);
      void queryClient.invalidateQueries({ queryKey: ['repayments', 'list'] });
    },
  });
}

export function useVerifyRepayment(id: number | string) {
  return useWorkflowAction(id, 'verify');
}

export function useRejectRepayment(id: number | string) {
  return useWorkflowAction(id, 'reject');
}

export function useApproveRepayment(id: number | string) {
  return useWorkflowAction(id, 'approve');
}

export function useReverseRepayment(id: number | string) {
  return useWorkflowAction(id, 'reverse');
}
