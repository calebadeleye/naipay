'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type { BankAccount, BankAccountFormInput } from '@/lib/bank-accounts/types';

export interface BankAccountOption {
  value: number;
  label: string;
  purposes: string[];
  is_default_collection_account: boolean;
  is_default_disbursement_account: boolean;
}

export function useBankAccountOptions() {
  return useQuery({
    queryKey: ['bank-accounts', 'options'],
    queryFn: ({ signal }) => api.get<BankAccountOption[]>('/admin/bank-accounts/options', { signal }),
    staleTime: 60_000,
  });
}

export const bankAccountKeys = {
  list: (params?: ListQuery) => ['bank-accounts', 'list', params ?? {}] as const,
  detail: (id: number | string) => ['bank-accounts', 'detail', String(id)] as const,
};

export function useBankAccounts(params?: ListQuery) {
  return useQuery({
    queryKey: bankAccountKeys.list(params),
    queryFn: ({ signal }) => api.list<BankAccount>('/admin/bank-accounts', { signal, query: params }),
  });
}

export function useBankAccount(id: number | string) {
  return useQuery({
    queryKey: bankAccountKeys.detail(id),
    queryFn: ({ signal }) => api.get<BankAccount>(`/admin/bank-accounts/${id}`, { signal }),
    enabled: Boolean(id),
  });
}

export function useCreateBankAccount() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: BankAccountFormInput) => api.post<BankAccount>('/admin/bank-accounts', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['bank-accounts'] });
    },
  });
}

export function useUpdateBankAccount(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: Partial<BankAccountFormInput>) =>
      api.patch<BankAccount>(`/admin/bank-accounts/${id}`, input),
    onSuccess: (account) => {
      queryClient.setQueryData(bankAccountKeys.detail(id), account);
      void queryClient.invalidateQueries({ queryKey: ['bank-accounts', 'list'] });
    },
  });
}

export function useApproveBankAccount(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => api.post<BankAccount>(`/admin/bank-accounts/${id}/approve`),
    onSuccess: (account) => {
      queryClient.setQueryData(bankAccountKeys.detail(id), account);
      void queryClient.invalidateQueries({ queryKey: ['bank-accounts', 'list'] });
    },
  });
}

export function useSetDefaultBankAccount(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (which: 'collection' | 'disbursement') =>
      api.post<BankAccount>(`/admin/bank-accounts/${id}/default`, { which }),
    onSuccess: (account) => {
      queryClient.setQueryData(bankAccountKeys.detail(id), account);
      void queryClient.invalidateQueries({ queryKey: ['bank-accounts', 'list'] });
    },
  });
}

export function useChangeBankAccountStatus(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { status: string; reason: string }) =>
      api.post<BankAccount>(`/admin/bank-accounts/${id}/status`, input),
    onSuccess: (account) => {
      queryClient.setQueryData(bankAccountKeys.detail(id), account);
      void queryClient.invalidateQueries({ queryKey: ['bank-accounts', 'list'] });
    },
  });
}
