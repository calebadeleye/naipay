import type { TokenStore } from '@naipay/api-client';

const STORAGE_KEY = 'naipay.investor.token';

/**
 * Holds the investor bearer token, kept entirely separate from
 * `naipay.admin.token` — see lib/auth/token-store.ts. A single browser can
 * otherwise never hold a staff session and an investor session at once
 * without one silently overwriting the other.
 */
export const investorTokenStore: TokenStore = {
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

export function storeInvestorToken(token: string): void {
  if (typeof window === 'undefined') {
    return;
  }

  window.sessionStorage.setItem(STORAGE_KEY, token);
}

export function clearInvestorToken(): void {
  investorTokenStore.clear();
}
