'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type { Investor, InvestorFormInput } from '@/lib/investors/types';

export const investorKeys = {
  list: (params?: ListQuery) => ['investors', 'list', params ?? {}] as const,
  detail: (id: number | string) => ['investors', 'detail', String(id)] as const,
};

export function useInvestorList(params?: ListQuery) {
  return useQuery({
    queryKey: investorKeys.list(params),
    queryFn: ({ signal }) => api.list<Investor>('/admin/investors', { signal, query: params }),
  });
}

export function useInvestor(id: number | string) {
  return useQuery({
    queryKey: investorKeys.detail(id),
    queryFn: ({ signal }) => api.get<Investor>(`/admin/investors/${id}`, { signal }),
    enabled: Boolean(id),
  });
}

export function useCreateInvestor() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: InvestorFormInput) =>
      api.post<{ investor: Investor; temporary_password: string }>('/admin/investors', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['investors', 'list'] });
    },
  });
}

export function useUpdateInvestor(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: Partial<InvestorFormInput>) => api.patch<Investor>(`/admin/investors/${id}`, input),
    onSuccess: (investor) => {
      queryClient.setQueryData(investorKeys.detail(id), investor);
      void queryClient.invalidateQueries({ queryKey: ['investors', 'list'] });
    },
  });
}

function invalidateInvestor(queryClient: ReturnType<typeof useQueryClient>, id: number | string) {
  void queryClient.invalidateQueries({ queryKey: investorKeys.detail(id) });
  void queryClient.invalidateQueries({ queryKey: ['investors', 'list'] });
}

export function useSuspendInvestor(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { reason: string }) => api.post<Investor>(`/admin/investors/${id}/suspend`, input),
    onSuccess: () => invalidateInvestor(queryClient, id),
  });
}

export function useReinstateInvestor(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { reason: string }) => api.post<Investor>(`/admin/investors/${id}/reinstate`, input),
    onSuccess: () => invalidateInvestor(queryClient, id),
  });
}
