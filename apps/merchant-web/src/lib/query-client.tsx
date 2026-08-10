'use client';

import { ApiError } from '@naipay/api-client';
import {
  QueryClient,
  QueryClientProvider,
  type QueryClientConfig,
} from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';

/**
 * React Query configuration for the merchant portal.
 *
 * Mirrors the admin console's defaults: balances and application status
 * change while a merchant is looking at them, so cached data is short-lived
 * and refetched on focus rather than shown stale.
 */
function buildConfig(): QueryClientConfig {
  return {
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        gcTime: 5 * 60_000,

        refetchOnWindowFocus: true,
        refetchOnReconnect: true,

        retry: (failureCount, error) => {
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
        // A failed mutation is always surfaced rather than silently retried —
        // an automatic retry on a loan application submission risks a
        // duplicate.
        retry: false,
      },
    },
  };
}

export function QueryProvider({ children }: { children: ReactNode }) {
  const [client] = useState(() => new QueryClient(buildConfig()));

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
