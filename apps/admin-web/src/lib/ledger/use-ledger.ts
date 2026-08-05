'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type { JournalTransaction, LedgerAccount } from '@/lib/ledger/types';

export const ledgerKeys = {
  list: (params?: ListQuery) => ['ledger', 'list', params ?? {}] as const,
  detail: (id: number | string) => ['ledger', 'detail', String(id)] as const,
  accounts: ['ledger', 'accounts'] as const,
};

export function useJournalTransactions(params?: ListQuery) {
  return useQuery({
    queryKey: ledgerKeys.list(params),
    queryFn: ({ signal }) => api.list<JournalTransaction>('/admin/ledger', { signal, query: params }),
  });
}

export function useJournalTransaction(id: number | string) {
  return useQuery({
    queryKey: ledgerKeys.detail(id),
    queryFn: ({ signal }) => api.get<JournalTransaction>(`/admin/ledger/${id}`, { signal }),
    enabled: Boolean(id),
  });
}

export function useReverseJournalTransaction(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { reason: string }) => api.post<JournalTransaction>(`/admin/ledger/${id}/reverse`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ledgerKeys.detail(id) });
      void queryClient.invalidateQueries({ queryKey: ['ledger', 'list'] });
    },
  });
}

export function useChartOfAccounts() {
  return useQuery({
    queryKey: ledgerKeys.accounts,
    queryFn: ({ signal }) => api.get<LedgerAccount[]>('/admin/ledger/accounts', { signal }),
  });
}
