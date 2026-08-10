import type { TokenStore } from '@naipay/api-client';

const STORAGE_KEY = 'naipay.merchant.token';

/**
 * Holds the merchant bearer token in this browser session. This app is
 * standalone — there is no staff or investor session sharing the same
 * origin — but the key is still namespaced for the same reason the admin
 * console namespaces its own: nothing here should ever accidentally collide.
 */
export const merchantTokenStore: TokenStore = {
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

export function storeMerchantToken(token: string): void {
  if (typeof window === 'undefined') {
    return;
  }

  window.sessionStorage.setItem(STORAGE_KEY, token);
}

export function clearMerchantToken(): void {
  merchantTokenStore.clear();
}
