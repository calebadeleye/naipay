'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';

import { investorApi } from '@/lib/investor-auth/api';
import { clearInvestorToken, storeInvestorToken } from '@/lib/investor-auth/token-store';
import type { Investor, InvestorSessionPayload } from '@/lib/investor-auth/types';

export const investorAuthKeys = {
  me: ['investor-auth', 'me'] as const,
};

/**
 * The signed-in investor. Retried never on failure: a 401 here means the
 * session is gone, and the investor API client has already redirected to
 * sign-in.
 */
export function useCurrentInvestor() {
  return useQuery({
    queryKey: investorAuthKeys.me,
    queryFn: ({ signal }) => investorApi.get<Investor>('/investor/auth/me', { signal }),
    retry: false,
    staleTime: 60_000,
  });
}

export function useInvestorLogin() {
  return useMutation({
    mutationFn: (credentials: { email: string; password: string }) =>
      investorApi.post<InvestorSessionPayload>('/investor/auth/login', credentials),
  });
}

/**
 * Persists a completed sign-in and routes onward. There is only one
 * destination — an investor has nowhere else to go.
 */
export function useEstablishInvestorSession() {
  const router = useRouter();
  const queryClient = useQueryClient();

  return (session: InvestorSessionPayload) => {
    storeInvestorToken(session.token);
    queryClient.setQueryData(investorAuthKeys.me, session.investor);

    router.replace('/investor/dashboard');
  };
}

export function useInvestorLogout() {
  const router = useRouter();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => investorApi.post<Record<string, never>>('/investor/auth/logout'),
    onSettled: () => {
      clearInvestorToken();
      queryClient.clear();
      router.replace('/investor');
    },
  });
}
