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

    if (window.location.pathname.startsWith('/sign-in')) {
      return;
    }

    // A hard navigation rather than router.push, deliberately: the session is
    // gone, so every cached query and every piece of component state holding
    // merchant or portfolio data should be discarded rather than carried
    // into the sign-in screen. A client-side transition would preserve it.
    //
    // The current page is only carried over as `return_to` when it isn't
    // the dashboard root — that's already where a sign-in lands by default,
    // so appending `?return_to=%2F` would just be noise in the address bar.
    const currentPath = window.location.pathname + window.location.search;
    const destination =
      currentPath === '/' ? '/sign-in' : `/sign-in?return_to=${encodeURIComponent(currentPath)}`;

    window.location.href = destination;
  },
});
