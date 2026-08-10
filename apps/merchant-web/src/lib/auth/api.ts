import { NaipayApiClient } from '@naipay/api-client';

import { merchantTokenStore } from '@/lib/auth/token-store';
import { env } from '@/lib/env';

/** Pages reachable without a session — never redirected away from on a 401. */
const PUBLIC_PATHS = ['/sign-in', '/activate', '/activate/request', '/forgot-password', '/reset-password'];

export const api = new NaipayApiClient({
  baseUrl: env.NEXT_PUBLIC_API_URL,
  tokenStore: merchantTokenStore,
  onUnauthenticated: () => {
    if (typeof window === 'undefined') {
      return;
    }

    if (!PUBLIC_PATHS.includes(window.location.pathname)) {
      // A hard navigation rather than router.push, deliberately: the session
      // is gone, so every cached query and figure should be discarded rather
      // than carried into the sign-in screen.
      // eslint-disable-next-line @next/next/no-location-assign-relative-destination
      window.location.href = '/sign-in';
    }
  },
});
