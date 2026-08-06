import { NaipayApiClient } from '@naipay/api-client';

import { investorTokenStore } from '@/lib/investor-auth/token-store';
import { env } from '@/lib/env';

/**
 * A separate client instance from `lib/api.ts`'s `api`: same API origin, but
 * its own token store and its own unauthenticated redirect, so an investor
 * session never reads or clears a staff session, or the reverse.
 */
export const investorApi = new NaipayApiClient({
  baseUrl: env.NEXT_PUBLIC_API_URL,
  tokenStore: investorTokenStore,
  onUnauthenticated: () => {
    if (typeof window === 'undefined') {
      return;
    }

    // Exact match, not startsWith: '/investor' is the public sign-in page,
    // but '/investor/dashboard' also starts with '/investor' and must still
    // redirect when the session is gone.
    if (window.location.pathname !== '/investor') {
      // A hard navigation rather than router.push, deliberately: the session is
      // gone, so every cached query and dashboard figure should be discarded
      // rather than carried into the sign-in screen.
      // eslint-disable-next-line @next/next/no-location-assign-relative-destination
      window.location.href = '/investor';
    }
  },
});
