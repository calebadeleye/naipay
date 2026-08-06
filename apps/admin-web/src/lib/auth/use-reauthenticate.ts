'use client';

import { ApiError } from '@naipay/api-client';
import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';

import { api } from '@/lib/api';

export function useReauthenticate() {
  return useMutation({
    mutationFn: (input: { password: string; code?: string; operation?: string }) =>
      api.post<Record<string, never>>('/admin/auth/reauthenticate', input),
  });
}

/**
 * Wraps a sensitive action (disburse, write off, reverse, ...) that the API
 * may refuse with HTTP 428 if the operator has not proven their identity
 * again recently — see naipay.security.reauthentication_required_operations.
 *
 * On a 428, the pending input is held and a reauthentication prompt is shown
 * in its place; confirming it transparently retries the original action, so
 * the caller never has to know the step happened.
 *
 * `operation` names which entry in reauthentication_required_operations this
 * protects — pass it for an operation listed in
 * reauthentication_two_factor_optional_operations (e.g. `staff.role_change`)
 * so the server accepts a password-only confirmation; omit it for anything
 * else and a two-factor code is required exactly as before.
 */
export function useProtectedAction<TInput, TResult>(
  action: (input: TInput) => Promise<TResult>,
  operation?: string,
) {
  const reauthenticate = useReauthenticate();
  const [pending, setPending] = useState<TInput | null>(null);
  const [running, setRunning] = useState(false);
  const [actionError, setActionError] = useState<ApiError | null>(null);

  async function run(input: TInput): Promise<TResult | undefined> {
    setRunning(true);
    setActionError(null);

    try {
      const result = await action(input);
      setRunning(false);

      return result;
    } catch (cause) {
      setRunning(false);

      if (cause instanceof ApiError && cause.status === 428) {
        setPending(input);

        return undefined;
      }

      // Recorded for the caller to render via `actionError` rather than
      // rethrown: every consumer fires `run()` from an onClick without
      // awaiting it, so a rethrow here would surface as an unhandled promise
      // rejection instead of the error message it is meant to become.
      setActionError(cause instanceof ApiError ? cause : null);

      return undefined;
    }
  }

  async function confirmReauthentication(password: string, code?: string): Promise<TResult | undefined> {
    try {
      await reauthenticate.mutateAsync({ password, code, operation });
    } catch {
      // Surfaced via `reauthenticationError` below; the prompt stays open so
      // the operator can correct the password or code and try again.
      return undefined;
    }

    const input = pending;
    setPending(null);

    if (input === null) {
      return undefined;
    }

    return run(input);
  }

  return {
    run,
    needsReauthentication: pending !== null,
    cancelReauthentication: () => setPending(null),
    confirmReauthentication,
    reauthenticating: reauthenticate.isPending,
    reauthenticationError: reauthenticate.error instanceof ApiError ? reauthenticate.error : null,
    running,
    actionError,
  };
}
