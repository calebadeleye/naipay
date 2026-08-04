import { NaipayApiClient } from '@naipay/api-client';

import { browserTokenStore } from '@/lib/auth/token-store';
import { env } from '@/lib/env';

/**
 * The API client used throughout the administrative console.
 *
 * A single instance so the token store and the unauthenticated handler are
 * shared by every call.
 */
export const api = new NaipayApiClient({
  baseUrl: env.NEXT_PUBLIC_API_URL,
  tokenStore: browserTokenStore,
  onUnauthenticated: () => {
    if (typeof window === 'undefined') {
      return;
    }

    // A full navigation rather than a router push: the session is gone, so
    // every cached query and piece of component state should be discarded
    // rather than carried into the sign-in screen.
    const returnTo = encodeURIComponent(window.location.pathname + window.location.search);

    if (!window.location.pathname.startsWith('/sign-in')) {
      // A hard navigation rather than router.push, deliberately: the session is
      // gone, so every cached query and every piece of component state holding
      // merchant or portfolio data should be discarded rather than carried
      // into the sign-in screen. A client-side transition would preserve it.
      // eslint-disable-next-line @next/next/no-location-assign-relative-destination
      window.location.href = `/sign-in?return_to=${returnTo}`;
    }
  },
});
