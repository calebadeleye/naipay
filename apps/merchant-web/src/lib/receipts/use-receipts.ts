'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useQuery } from '@tanstack/react-query';

import { api } from '@/lib/auth/api';
import type { Receipt } from '@/lib/receipts/types';

export function useReceiptList(params?: ListQuery) {
  return useQuery({
    queryKey: ['receipts', 'list', params ?? {}],
    queryFn: ({ signal }) => api.list<Receipt>('/merchant/receipts', { signal, query: params }),
  });
}

export function useReceipt(id: number | string) {
  return useQuery({
    queryKey: ['receipts', 'detail', String(id)],
    queryFn: ({ signal }) => api.get<Receipt>(`/merchant/receipts/${id}`, { signal }),
    enabled: Boolean(id),
  });
}
