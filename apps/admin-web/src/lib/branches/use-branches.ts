'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type { Branch, BranchFormInput, BranchOption } from '@/lib/branches/types';

export const branchKeys = {
  list: (params?: ListQuery) => ['branches', 'list', params ?? {}] as const,
  detail: (id: number | string) => ['branches', 'detail', String(id)] as const,
};

export function useBranches(params?: ListQuery) {
  return useQuery({
    queryKey: branchKeys.list(params),
    queryFn: ({ signal }) => api.list<Branch>('/admin/branches', { signal, query: params }),
  });
}

export function useBranch(id: number | string) {
  return useQuery({
    queryKey: branchKeys.detail(id),
    queryFn: ({ signal }) => api.get<Branch>(`/admin/branches/${id}`, { signal }),
    enabled: Boolean(id),
  });
}

export function useBranchOptions() {
  return useQuery({
    queryKey: ['branches', 'options'],
    queryFn: ({ signal }) => api.get<BranchOption[]>('/admin/branches/options', { signal }),
    staleTime: 60_000,
  });
}

export function useCreateBranch() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: BranchFormInput) => api.post<Branch>('/admin/branches', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['branches'] });
    },
  });
}

export function useUpdateBranch(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: Partial<BranchFormInput>) => api.patch<Branch>(`/admin/branches/${id}`, input),
    onSuccess: (branch) => {
      queryClient.setQueryData(branchKeys.detail(id), branch);
      void queryClient.invalidateQueries({ queryKey: ['branches', 'list'] });
    },
  });
}

export function useChangeBranchStatus(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { status: string; reason: string }) =>
      api.post<Branch>(`/admin/branches/${id}/status`, input),
    onSuccess: (branch) => {
      queryClient.setQueryData(branchKeys.detail(id), branch);
      void queryClient.invalidateQueries({ queryKey: ['branches', 'list'] });
    },
  });
}
