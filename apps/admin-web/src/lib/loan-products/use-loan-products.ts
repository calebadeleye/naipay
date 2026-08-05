'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type { LoanProduct, LoanProductFormInput, SchedulePreview } from '@/lib/loan-products/types';

export const loanProductKeys = {
  list: (params?: ListQuery) => ['loan-products', 'list', params ?? {}] as const,
  detail: (id: number | string) => ['loan-products', 'detail', String(id)] as const,
};

export function useLoanProducts(params?: ListQuery) {
  return useQuery({
    queryKey: loanProductKeys.list(params),
    queryFn: ({ signal }) => api.list<LoanProduct>('/admin/loan-products', { signal, query: params }),
  });
}

export interface LoanProductOption {
  value: number;
  label: string;
  code: string;
  summary: string;
  frequency: string;
  minimum_amount: string;
  maximum_amount: string;
  minimum_tenor: number;
  maximum_tenor: number;
  default_tenor: number | null;
  tenor_unit: string;
}

export function useLoanProductOptions() {
  return useQuery({
    queryKey: ['loan-products', 'options'],
    queryFn: ({ signal }) => api.get<LoanProductOption[]>('/admin/loan-products/options', { signal }),
    staleTime: 60_000,
  });
}

export function useLoanProduct(id: number | string) {
  return useQuery({
    queryKey: loanProductKeys.detail(id),
    queryFn: ({ signal }) => api.get<LoanProduct>(`/admin/loan-products/${id}`, { signal }),
    enabled: Boolean(id),
  });
}

export function useCreateLoanProduct() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: LoanProductFormInput) => api.post<LoanProduct>('/admin/loan-products', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['loan-products', 'list'] });
    },
  });
}

export function useUpdateLoanProduct(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: Partial<LoanProductFormInput>) =>
      api.patch<LoanProduct>(`/admin/loan-products/${id}`, input),
    onSuccess: (product) => {
      queryClient.setQueryData(loanProductKeys.detail(id), product);
      void queryClient.invalidateQueries({ queryKey: ['loan-products', 'list'] });
    },
  });
}

export function useChangeLoanProductStatus(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { status: 'active' | 'retired'; reason: string }) =>
      api.post<LoanProduct>(`/admin/loan-products/${id}/status`, input),
    onSuccess: (product) => {
      queryClient.setQueryData(loanProductKeys.detail(id), product);
      void queryClient.invalidateQueries({ queryKey: ['loan-products', 'list'] });
    },
  });
}

export function usePreviewLoanProduct(id: number | string) {
  return useMutation({
    mutationFn: (input: { amount: string; tenor: number; disbursement_date?: string }) =>
      api.post<SchedulePreview>(`/admin/loan-products/${id}/preview`, input),
  });
}
