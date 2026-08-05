'use client';

import { useQuery } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type {
  ComplianceOverview,
  CollectionsReport,
  DashboardSummary,
  DelinquencyReport,
  LoanPortfolio,
  TrialBalance,
} from '@/lib/reports/types';

export const reportKeys = {
  dashboard: ['reports', 'dashboard'] as const,
  trialBalance: (asOf?: string) => ['reports', 'trial-balance', asOf ?? null] as const,
  loanPortfolio: ['reports', 'loan-portfolio'] as const,
  collections: (from?: string, to?: string) =>
    ['reports', 'collections', from ?? null, to ?? null] as const,
  delinquency: ['reports', 'delinquency'] as const,
  complianceOverview: ['reports', 'compliance-overview'] as const,
};

export function useDashboard() {
  return useQuery({
    queryKey: reportKeys.dashboard,
    queryFn: ({ signal }) => api.get<DashboardSummary>('/admin/dashboard', { signal }),
  });
}

export function useTrialBalance(asOf?: string) {
  return useQuery({
    queryKey: reportKeys.trialBalance(asOf),
    queryFn: ({ signal }) =>
      api.get<TrialBalance>('/admin/reports/trial-balance', {
        signal,
        query: asOf ? { as_of: asOf } : undefined,
      }),
  });
}

export function useLoanPortfolio() {
  return useQuery({
    queryKey: reportKeys.loanPortfolio,
    queryFn: ({ signal }) => api.get<LoanPortfolio>('/admin/reports/loan-portfolio', { signal }),
  });
}

export function useCollections(from?: string, to?: string) {
  return useQuery({
    queryKey: reportKeys.collections(from, to),
    queryFn: ({ signal }) =>
      api.get<CollectionsReport>('/admin/reports/collections', {
        signal,
        query: { from, to },
      }),
  });
}

export function useDelinquency() {
  return useQuery({
    queryKey: reportKeys.delinquency,
    queryFn: ({ signal }) => api.get<DelinquencyReport>('/admin/reports/delinquency', { signal }),
  });
}

export function useComplianceOverview() {
  return useQuery({
    queryKey: reportKeys.complianceOverview,
    queryFn: ({ signal }) =>
      api.get<ComplianceOverview>('/admin/reports/compliance-overview', { signal }),
  });
}
