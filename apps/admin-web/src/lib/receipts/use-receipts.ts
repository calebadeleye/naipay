'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useQuery } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type { Receipt } from '@/lib/receipts/types';

export function useReceipts(params?: ListQuery) {
  return useQuery({
    queryKey: ['receipts', 'list', params ?? {}],
    queryFn: ({ signal }) => api.list<Receipt>('/admin/receipts', { signal, query: params }),
  });
}

export function useReceipt(id: number | string) {
  return useQuery({
    queryKey: ['receipts', 'detail', String(id)],
    queryFn: ({ signal }) => api.get<Receipt>(`/admin/receipts/${id}`, { signal }),
    enabled: Boolean(id),
  });
}
