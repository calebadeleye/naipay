'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type {
  Guarantor,
  GuarantorFormInput,
  LoanApplication,
  LoanApplicationFormInput,
} from '@/lib/loan-applications/types';

export const loanApplicationKeys = {
  list: (params?: ListQuery) => ['loan-applications', 'list', params ?? {}] as const,
  detail: (id: number | string) => ['loan-applications', 'detail', String(id)] as const,
  guarantors: (id: number | string) => ['loan-applications', 'guarantors', String(id)] as const,
};

export function useLoanApplications(params?: ListQuery) {
  return useQuery({
    queryKey: loanApplicationKeys.list(params),
    queryFn: ({ signal }) =>
      api.list<LoanApplication>('/admin/loan-applications', { signal, query: params }),
  });
}

export function useLoanApplication(id: number | string) {
  return useQuery({
    queryKey: loanApplicationKeys.detail(id),
    queryFn: ({ signal }) => api.get<LoanApplication>(`/admin/loan-applications/${id}`, { signal }),
    enabled: Boolean(id),
  });
}

export function useCreateLoanApplication() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: LoanApplicationFormInput) =>
      api.post<LoanApplication>('/admin/loan-applications', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['loan-applications', 'list'] });
    },
  });
}

export function useUpdateLoanApplication(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: Partial<LoanApplicationFormInput>) =>
      api.patch<LoanApplication>(`/admin/loan-applications/${id}`, input),
    onSuccess: (application) => {
      queryClient.setQueryData(loanApplicationKeys.detail(id), application);
      void queryClient.invalidateQueries({ queryKey: ['loan-applications', 'list'] });
    },
  });
}

function useWorkflowAction(id: number | string, action: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input?: Record<string, unknown>) =>
      api.post<LoanApplication>(`/admin/loan-applications/${id}/${action}`, input),
    onSuccess: (application) => {
      queryClient.setQueryData(loanApplicationKeys.detail(id), application);
      void queryClient.invalidateQueries({ queryKey: ['loan-applications', 'list'] });
    },
  });
}

export function useSubmitLoanApplication(id: number | string) {
  return useWorkflowAction(id, 'submit');
}

export function useAssessLoanApplication(id: number | string) {
  return useWorkflowAction(id, 'assess');
}

export function useRecommendLoanApplication(id: number | string) {
  return useWorkflowAction(id, 'recommend');
}

export function useApproveLoanApplication(id: number | string) {
  return useWorkflowAction(id, 'approve');
}

export function useRejectLoanApplication(id: number | string) {
  return useWorkflowAction(id, 'reject');
}

export function useReturnLoanApplicationToDraft(id: number | string) {
  return useWorkflowAction(id, 'return-to-draft');
}

export function useWithdrawLoanApplication(id: number | string) {
  return useWorkflowAction(id, 'withdraw');
}

export function useAddGuarantor(applicationId: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: GuarantorFormInput) =>
      api.post<Guarantor>(`/admin/loan-applications/${applicationId}/guarantors`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: loanApplicationKeys.detail(applicationId) });
    },
  });
}

export function useRemoveGuarantor(applicationId: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (guarantorId: number) =>
      api.delete(`/admin/loan-applications/${applicationId}/guarantors/${guarantorId}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: loanApplicationKeys.detail(applicationId) });
    },
  });
}
