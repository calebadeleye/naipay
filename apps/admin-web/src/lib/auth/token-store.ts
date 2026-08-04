import type { TokenStore } from '@naipay/api-client';

const STORAGE_KEY = 'naipay.admin.token';

/**
 * Holds the administrative bearer token.
 *
 * Kept in `sessionStorage` rather than `localStorage`: an operator's token
 * should not outlive the browser tab, which limits exposure on the shared
 * branch workstations this console runs on. It is cleared on sign-out, on any
 * 401, and when the tab closes.
 *
 * The token is deliberately not held in a JavaScript-readable cookie either,
 * since the API is stateless and bearer-authenticated.
 *
 * A hardened alternative — an httpOnly cookie set by a Next.js route handler —
 * is the intended follow-up in the security phase; the client only depends on
 * this interface, so swapping the implementation touches nothing else.
 */
export const browserTokenStore: TokenStore = {
  get(): string | null {
    if (typeof window === 'undefined') {
      return null;
    }

    return window.sessionStorage.getItem(STORAGE_KEY);
  },

  clear(): void {
    if (typeof window === 'undefined') {
      return;
    }

    window.sessionStorage.removeItem(STORAGE_KEY);
  },
};

export function storeToken(token: string): void {
  if (typeof window === 'undefined') {
    return;
  }

  window.sessionStorage.setItem(STORAGE_KEY, token);
}

export function clearToken(): void {
  browserTokenStore.clear();
}
