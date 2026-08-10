'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';

import { api } from '@/lib/auth/api';
import { clearMerchantToken, storeMerchantToken } from '@/lib/auth/token-store';
import type { Merchant, MerchantSessionPayload } from '@/lib/auth/types';

export const authKeys = {
  me: ['auth', 'me'] as const,
};

/**
 * The signed-in merchant. Retried never on failure: a 401 here means the
 * session is gone, and the API client has already redirected to sign-in.
 */
export function useCurrentMerchant() {
  return useQuery({
    queryKey: authKeys.me,
    queryFn: ({ signal }) => api.get<Merchant>('/merchant/auth/me', { signal }),
    retry: false,
    staleTime: 60_000,
  });
}

export function useLogin() {
  return useMutation({
    mutationFn: (credentials: { email: string; password: string }) =>
      api.post<MerchantSessionPayload>('/merchant/auth/login', credentials),
  });
}

/**
 * Persists a completed sign-in and routes to the dashboard.
 */
export function useEstablishSession() {
  const router = useRouter();
  const queryClient = useQueryClient();

  return (session: MerchantSessionPayload) => {
    storeMerchantToken(session.token);
    queryClient.setQueryData(authKeys.me, session.merchant);

    router.replace('/');
  };
}

export function useLogout() {
  const router = useRouter();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => api.post<Record<string, never>>('/merchant/auth/logout'),
    onSettled: () => {
      clearMerchantToken();
      queryClient.clear();
      router.replace('/sign-in');
    },
  });
}

export function useRequestActivation() {
  return useMutation({
    mutationFn: (input: { email: string }) =>
      api.post<Record<string, never>>('/merchant/auth/activate/request', input),
  });
}

export function useActivate() {
  return useMutation({
    mutationFn: (input: { email: string; token: string; password: string; password_confirmation: string }) =>
      api.post<Record<string, never>>('/merchant/auth/activate', input),
  });
}

export function useForgotPassword() {
  return useMutation({
    mutationFn: (input: { email: string }) =>
      api.post<Record<string, never>>('/merchant/auth/forgot-password', input),
  });
}

export function useResetPassword() {
  return useMutation({
    mutationFn: (input: { email: string; token: string; password: string; password_confirmation: string }) =>
      api.post<Record<string, never>>('/merchant/auth/reset-password', input),
  });
}
