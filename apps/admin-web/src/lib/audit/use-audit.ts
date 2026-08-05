'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useQuery } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type { AuditLog } from '@/lib/audit/types';

export function useAuditLogs(params?: ListQuery) {
  return useQuery({
    queryKey: ['audit-logs', 'list', params ?? {}],
    queryFn: ({ signal }) => api.list<AuditLog>('/admin/audit-logs', { signal, query: params }),
  });
}

export function useAuditLog(id: number | string) {
  return useQuery({
    queryKey: ['audit-logs', 'detail', String(id)],
    queryFn: ({ signal }) => api.get<AuditLog>(`/admin/audit-logs/${id}`, { signal }),
    enabled: Boolean(id),
  });
}
