'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type { Business, BusinessFormInput } from '@/lib/businesses/types';

export const businessKeys = {
  list: (params?: ListQuery) => ['businesses', 'list', params ?? {}] as const,
  detail: (id: number | string) => ['businesses', 'detail', String(id)] as const,
  types: ['businesses', 'types'] as const,
  categoryOptions: ['business-categories', 'options'] as const,
};

export function useBusinesses(params?: ListQuery) {
  return useQuery({
    queryKey: businessKeys.list(params),
    queryFn: ({ signal }) => api.list<Business>('/admin/businesses', { signal, query: params }),
  });
}

export function useBusiness(id: number | string) {
  return useQuery({
    queryKey: businessKeys.detail(id),
    queryFn: ({ signal }) => api.get<Business>(`/admin/businesses/${id}`, { signal }),
    enabled: Boolean(id),
  });
}

export interface BusinessTypeOption {
  value: string;
  label: string;
  requires_cac: boolean;
}

export function useBusinessTypeOptions() {
  return useQuery({
    queryKey: businessKeys.types,
    queryFn: ({ signal }) =>
      api.get<BusinessTypeOption[]>('/admin/businesses/types', { signal }),
    staleTime: Infinity,
  });
}

export interface CategoryOption {
  value: number;
  label: string;
  icon: string | null;
  children: { value: number; label: string; qualified_label: string }[];
}

export function useCategoryOptions() {
  return useQuery({
    queryKey: businessKeys.categoryOptions,
    queryFn: ({ signal }) =>
      api.get<CategoryOption[]>('/admin/business-categories/options', { signal }),
    staleTime: Infinity,
  });
}

export function useCreateBusiness(merchantId: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: BusinessFormInput) =>
      api.post<Business>(`/admin/merchants/${merchantId}/businesses`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['merchants', 'detail', String(merchantId)] });
      void queryClient.invalidateQueries({ queryKey: ['businesses', 'list'] });
    },
  });
}

export function useUpdateBusiness(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: Partial<BusinessFormInput>) =>
      api.patch<Business>(`/admin/businesses/${id}`, input),
    onSuccess: (business) => {
      queryClient.setQueryData(businessKeys.detail(id), business);
      void queryClient.invalidateQueries({ queryKey: ['businesses', 'list'] });
    },
  });
}

export function useVerifyBusiness(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => api.post<Business>(`/admin/businesses/${id}/verify`),
    onSuccess: (business) => {
      queryClient.setQueryData(businessKeys.detail(id), business);
      void queryClient.invalidateQueries({ queryKey: ['businesses', 'list'] });
    },
  });
}

export function useRejectBusinessVerification(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { reason: string }) =>
      api.post<Business>(`/admin/businesses/${id}/reject-verification`, input),
    onSuccess: (business) => {
      queryClient.setQueryData(businessKeys.detail(id), business);
      void queryClient.invalidateQueries({ queryKey: ['businesses', 'list'] });
    },
  });
}

export function useChangeBusinessStatus(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { status: string; reason: string }) =>
      api.post<Business>(`/admin/businesses/${id}/status`, input),
    onSuccess: (business) => {
      queryClient.setQueryData(businessKeys.detail(id), business);
      void queryClient.invalidateQueries({ queryKey: ['businesses', 'list'] });
    },
  });
}
