'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type {
  BankReconciliation,
  BankStatementLine,
  MatchSuggestion,
  OpenReconciliationInput,
  StatementLineInput,
} from '@/lib/reconciliation/types';

export const reconciliationKeys = {
  list: (params?: ListQuery) => ['reconciliations', 'list', params ?? {}] as const,
  detail: (id: number | string) => ['reconciliations', 'detail', String(id)] as const,
};

export function useReconciliations(params?: ListQuery) {
  return useQuery({
    queryKey: reconciliationKeys.list(params),
    queryFn: ({ signal }) => api.list<BankReconciliation>('/admin/reconciliations', { signal, query: params }),
  });
}

export function useReconciliation(id: number | string) {
  return useQuery({
    queryKey: reconciliationKeys.detail(id),
    queryFn: ({ signal }) => api.get<BankReconciliation>(`/admin/reconciliations/${id}`, { signal }),
    enabled: Boolean(id),
  });
}

export function useOpenReconciliation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: OpenReconciliationInput) =>
      api.post<BankReconciliation>('/admin/reconciliations', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['reconciliations', 'list'] });
    },
  });
}

function invalidateReconciliation(queryClient: ReturnType<typeof useQueryClient>, id: number | string) {
  void queryClient.invalidateQueries({ queryKey: reconciliationKeys.detail(id) });
  void queryClient.invalidateQueries({ queryKey: ['reconciliations', 'list'] });
}

export function useAddStatementLine(reconciliationId: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: StatementLineInput) =>
      api.post<BankStatementLine>(`/admin/reconciliations/${reconciliationId}/lines`, input),
    onSuccess: () => invalidateReconciliation(queryClient, reconciliationId),
  });
}

export function useMatchSuggestions(reconciliationId: number | string, lineId: number | null) {
  return useQuery({
    queryKey: ['reconciliations', 'suggestions', String(reconciliationId), lineId],
    queryFn: ({ signal }) =>
      api.get<MatchSuggestion[]>(
        `/admin/reconciliations/${reconciliationId}/lines/${lineId}/suggestions`,
        { signal },
      ),
    enabled: lineId !== null,
  });
}

export function useMatchLine(reconciliationId: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { lineId: number; matched_to_type: string; matched_to_id: number }) =>
      api.post<BankStatementLine>(
        `/admin/reconciliations/${reconciliationId}/lines/${input.lineId}/match`,
        { matched_to_type: input.matched_to_type, matched_to_id: input.matched_to_id },
      ),
    onSuccess: () => invalidateReconciliation(queryClient, reconciliationId),
  });
}

export function useUnmatchLine(reconciliationId: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (lineId: number) =>
      api.post<BankStatementLine>(`/admin/reconciliations/${reconciliationId}/lines/${lineId}/unmatch`),
    onSuccess: () => invalidateReconciliation(queryClient, reconciliationId),
  });
}

export function useExcludeLine(reconciliationId: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { lineId: number; reason: string }) =>
      api.post<BankStatementLine>(`/admin/reconciliations/${reconciliationId}/lines/${input.lineId}/exclude`, {
        reason: input.reason,
      }),
    onSuccess: () => invalidateReconciliation(queryClient, reconciliationId),
  });
}

export function useSubmitReconciliation(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => api.post<BankReconciliation>(`/admin/reconciliations/${id}/submit`),
    onSuccess: (reconciliation) => {
      queryClient.setQueryData(reconciliationKeys.detail(id), reconciliation);
      void queryClient.invalidateQueries({ queryKey: ['reconciliations', 'list'] });
    },
  });
}

export function useApproveReconciliation(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => api.post<BankReconciliation>(`/admin/reconciliations/${id}/approve`),
    onSuccess: (reconciliation) => {
      queryClient.setQueryData(reconciliationKeys.detail(id), reconciliation);
      void queryClient.invalidateQueries({ queryKey: ['reconciliations', 'list'] });
    },
  });
}
