'use client';

import { ApiError } from '@naipay/api-client';
import {
  QueryClient,
  QueryClientProvider,
  type QueryClientConfig,
} from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';

/**
 * React Query configuration for the administrative console.
 *
 * The defaults lean conservative because this is a financial back office: an
 * operator looking at a loan balance or an approval queue must not be shown a
 * stale figure, so cached data is short-lived and refetched on focus.
 */
function buildConfig(): QueryClientConfig {
  return {
    defaultOptions: {
      queries: {
        // Balances, approval queues and reconciliation state change while an
        // operator is looking at them. Thirty seconds keeps navigation snappy
        // without showing figures that have moved on.
        staleTime: 30_000,
        gcTime: 5 * 60_000,

        refetchOnWindowFocus: true,
        refetchOnReconnect: true,

        retry: (failureCount, error) => {
          // Retrying an authorisation or validation failure just repeats the
          // same refusal; only transient faults are worth another attempt.
          if (error instanceof ApiError) {
            if (error.status < 500 && !error.isRateLimited) {
              return false;
            }
          }

          return failureCount < 2;
        },

        retryDelay: (attempt) => Math.min(1_000 * 2 ** attempt, 8_000),
      },

      mutations: {
        // Mutations here post repayments, approve loans and write to the
        // ledger. An automatic retry risks a duplicate financial action, so a
        // failed mutation is always surfaced to the operator instead.
        retry: false,
      },
    },
  };
}

export function QueryProvider({ children }: { children: ReactNode }) {
  // Created in state so each browser session gets one client, and so a server
  // render never shares a cache between requests.
  const [client] = useState(() => new QueryClient(buildConfig()));

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
