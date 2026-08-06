'use client';

import type { ListQuery } from '@naipay/shared-types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import { ROLE_OPTIONS, type RoleOption } from '@/lib/staff/types';
import type { StaffFormInput, StaffMember } from '@/lib/staff/types';

export const staffKeys = {
  list: (params?: ListQuery) => ['staff', 'list', params ?? {}] as const,
  detail: (id: number | string) => ['staff', 'detail', String(id)] as const,
};

export function useStaffList(params?: ListQuery) {
  return useQuery({
    queryKey: staffKeys.list(params),
    queryFn: ({ signal }) => api.list<StaffMember>('/admin/staff', { signal, query: params }),
  });
}

export function useStaffMember(id: number | string) {
  return useQuery({
    queryKey: staffKeys.detail(id),
    queryFn: ({ signal }) => api.get<StaffMember>(`/admin/staff/${id}`, { signal }),
    enabled: Boolean(id),
  });
}

export function useCreateStaff() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: StaffFormInput) =>
      api.post<{ staff: StaffMember; temporary_password: string }>('/admin/staff', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['staff', 'list'] });
    },
  });
}

export function useUpdateStaff(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: Partial<StaffFormInput>) => api.patch<StaffMember>(`/admin/staff/${id}`, input),
    onSuccess: (staff) => {
      queryClient.setQueryData(staffKeys.detail(id), staff);
      void queryClient.invalidateQueries({ queryKey: ['staff', 'list'] });
    },
  });
}

function invalidateStaff(queryClient: ReturnType<typeof useQueryClient>, id: number | string) {
  void queryClient.invalidateQueries({ queryKey: staffKeys.detail(id) });
  void queryClient.invalidateQueries({ queryKey: ['staff', 'list'] });
}

export function useTransferStaff(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { branch_id: number; reason: string }) =>
      api.post<StaffMember>(`/admin/staff/${id}/transfer`, input),
    onSuccess: () => invalidateStaff(queryClient, id),
  });
}

export function useSuspendStaff(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { reason: string }) => api.post<StaffMember>(`/admin/staff/${id}/suspend`, input),
    onSuccess: () => invalidateStaff(queryClient, id),
  });
}

export function useReinstateStaff(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { reason: string }) => api.post<StaffMember>(`/admin/staff/${id}/reinstate`, input),
    onSuccess: () => invalidateStaff(queryClient, id),
  });
}

export function useDisableStaff(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { reason: string }) => api.post<StaffMember>(`/admin/staff/${id}/disable`, input),
    onSuccess: () => invalidateStaff(queryClient, id),
  });
}

/**
 * The roles an admin viewing this console may grant to someone else.
 *
 * Super Administrator is never among them, for anyone — see the note on
 * ROLE_OPTIONS. The API refuses the grant regardless of what the console
 * renders; see StaffManagementService::ensureSingleSuperAdministrator().
 */
export function useAssignableRoleOptions(): RoleOption[] {
  return ROLE_OPTIONS;
}

export function useAssignStaffRoles(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (roles: string[]) => api.put<StaffMember>(`/admin/staff/${id}/roles`, { roles }),
    onSuccess: () => invalidateStaff(queryClient, id),
  });
}

export function useSetStaffApprovalLimit(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { approval_limit: string | null; reason: string }) =>
      api.put<StaffMember>(`/admin/staff/${id}/approval-limit`, input),
    onSuccess: () => invalidateStaff(queryClient, id),
  });
}

export function useResetStaffPassword(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => api.post<{ temporary_password: string }>(`/admin/staff/${id}/reset-password`),
    onSuccess: () => invalidateStaff(queryClient, id),
  });
}
