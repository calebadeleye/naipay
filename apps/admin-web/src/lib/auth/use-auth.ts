'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';

import { api } from '@/lib/api';
import { clearToken, storeToken } from '@/lib/auth/token-store';
import type {
  LoginResult,
  SessionPayload,
  Staff,
} from '@/lib/auth/types';

export const authKeys = {
  me: ['auth', 'me'] as const,
};

/**
 * The signed-in staff member.
 *
 * Retried never on failure: a 401 here means the session is gone, and the API
 * client has already redirected to sign-in.
 */
export function useCurrentStaff() {
  return useQuery({
    queryKey: authKeys.me,
    queryFn: ({ signal }) => api.get<Staff>('/admin/auth/me', { signal }),
    retry: false,
    staleTime: 60_000,
  });
}

/**
 * Where to send an operator once they hold a token.
 *
 * A forced password change or mandatory two-factor enrolment must be resolved
 * before anything else will succeed, so the destination is decided by the API's
 * `required_action` rather than assumed.
 */
export function destinationFor(session: SessionPayload, returnTo?: string | null): string {
  if (session.required_action === 'change_password') {
    return '/change-password';
  }

  if (session.required_action === 'enrol_two_factor') {
    return '/two-factor/enrol';
  }

  // An open redirect would let a phishing link bounce a freshly signed-in
  // operator to an attacker's page, so only same-site paths are honoured.
  if (returnTo && returnTo.startsWith('/') && !returnTo.startsWith('//')) {
    return returnTo;
  }

  return '/';
}

export function useLogin() {
  return useMutation({
    mutationFn: (credentials: { identifier: string; password: string }) =>
      api.post<LoginResult>('/admin/auth/login', credentials),
  });
}

export function useTwoFactorChallenge() {
  return useMutation({
    mutationFn: (input: { challenge_token: string; code: string }) =>
      api.post<SessionPayload>('/admin/auth/two-factor/challenge', input),
  });
}

export function useForgotPassword() {
  return useMutation({
    mutationFn: (input: { email: string }) =>
      api.post<Record<string, never>>('/admin/auth/forgot-password', input),
  });
}

export function useResetPassword() {
  return useMutation({
    mutationFn: (input: {
      email: string;
      token: string;
      password: string;
      password_confirmation: string;
    }) => api.post<Record<string, never>>('/admin/auth/reset-password', input),
  });
}

export function useChangePassword() {
  return useMutation({
    mutationFn: (input: {
      current_password: string;
      password: string;
      password_confirmation: string;
    }) => api.post<Record<string, never>>('/admin/auth/password', input),
  });
}

/**
 * Persists a completed sign-in and routes onward.
 */
export function useEstablishSession() {
  const router = useRouter();
  const queryClient = useQueryClient();

  return (session: SessionPayload, returnTo?: string | null) => {
    storeToken(session.token);
    queryClient.setQueryData(authKeys.me, session.staff);

    router.replace(destinationFor(session, returnTo));
  };
}

export function useLogout() {
  const router = useRouter();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => api.post<Record<string, never>>('/admin/auth/logout'),
    // Runs whether or not the request succeeded: if the token is already
    // invalid, the local session must still be cleared.
    onSettled: () => {
      clearToken();
      queryClient.clear();
      router.replace('/sign-in');
    },
  });
}
