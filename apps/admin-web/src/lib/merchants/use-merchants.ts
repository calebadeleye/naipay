'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type { Merchant, MerchantFormInput } from '@/lib/merchants/types';

export const merchantKeys = {
  list: (params?: ListQuery) => ['merchants', 'list', params ?? {}] as const,
  detail: (id: number | string) => ['merchants', 'detail', String(id)] as const,
};

export function useMerchants(params?: ListQuery) {
  return useQuery({
    queryKey: merchantKeys.list(params),
    queryFn: ({ signal }) =>
      api.list<Merchant>('/admin/merchants', { signal, query: params }),
  });
}

export function useMerchant(id: number | string) {
  return useQuery({
    queryKey: merchantKeys.detail(id),
    queryFn: ({ signal }) => api.get<Merchant>(`/admin/merchants/${id}`, { signal }),
    enabled: Boolean(id),
  });
}

export function useCreateMerchant() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: MerchantFormInput) => api.post<Merchant>('/admin/merchants', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['merchants', 'list'] });
    },
  });
}

export function useUpdateMerchant(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: Partial<MerchantFormInput>) =>
      api.patch<Merchant>(`/admin/merchants/${id}`, input),
    onSuccess: (merchant) => {
      queryClient.setQueryData(merchantKeys.detail(id), merchant);
      void queryClient.invalidateQueries({ queryKey: ['merchants', 'list'] });
    },
  });
}

function useWorkflowAction(id: number | string, action: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input?: { reason?: string }) =>
      api.post<Merchant>(`/admin/merchants/${id}/${action}`, input),
    onSuccess: (merchant) => {
      queryClient.setQueryData(merchantKeys.detail(id), merchant);
      void queryClient.invalidateQueries({ queryKey: ['merchants', 'list'] });
    },
  });
}

export function useSubmitMerchant(id: number | string) {
  return useWorkflowAction(id, 'submit');
}

export function useVerifyMerchant(id: number | string) {
  return useWorkflowAction(id, 'verify');
}

export function useApproveMerchant(id: number | string) {
  return useWorkflowAction(id, 'approve');
}

export function useRejectMerchant(id: number | string) {
  return useWorkflowAction(id, 'reject');
}

export function useSuspendMerchant(id: number | string) {
  return useWorkflowAction(id, 'suspend');
}

export function useReinstateMerchant(id: number | string) {
  return useWorkflowAction(id, 'reinstate');
}

export function useReturnMerchantToDraft(id: number | string) {
  return useWorkflowAction(id, 'return-to-draft');
}
