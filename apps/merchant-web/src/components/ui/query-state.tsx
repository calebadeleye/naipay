'use client';

import { ApiError } from '@naipay/api-client';
import { Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';

import { Alert } from '@/components/ui/field';

interface QueryStateProps {
  isLoading: boolean;
  error: unknown;
  children: ReactNode;
}

export function QueryState({ isLoading, error, children }: QueryStateProps) {
  if (isLoading) {
    return (
      <div className="flex items-center justify-center gap-2 py-16 text-sm text-slate-500">
        <Loader2 className="size-4 animate-spin" aria-hidden />
        Loading…
      </div>
    );
  }

  if (error) {
    const message =
      error instanceof ApiError ? error.message : 'Could not reach Every Merchant. Try again shortly.';
    const reference = error instanceof ApiError ? error.correlationId : null;

    return (
      <Alert tone="error" reference={reference}>
        {message}
      </Alert>
    );
  }

  return <>{children}</>;
}
