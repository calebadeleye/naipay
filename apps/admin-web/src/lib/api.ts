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
      window.location.href = `/sign-in?return_to=${returnTo}`;
    }
  },
});
