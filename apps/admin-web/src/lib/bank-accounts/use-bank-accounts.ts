'use client';

import { useQuery } from '@tanstack/react-query';

import { api } from '@/lib/api';

export interface BankAccountOption {
  value: number;
  label: string;
  account_purpose: string;
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
