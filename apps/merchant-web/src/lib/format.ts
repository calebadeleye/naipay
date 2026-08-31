import type { MoneyValue } from '@naipay/shared-types';
import { format, parseISO } from 'date-fns';

/**
 * Presentation helpers, mirroring the admin console's — timestamps arrive
 * from the API in UTC and are rendered locally; amounts arrive as exact
 * decimal strings and are never parsed into a JavaScript number for anything
 * other than display.
 */

/**
 * Formats an amount for display. Prefers the `formatted` string the API
 * already produced, so the server stays the single authority on how an
 * amount reads.
 */
export function formatMoney(value: MoneyValue | null | undefined): string {
  if (!value) {
    return '—';
  }

  if (value.formatted) {
    return value.formatted;
  }

  return formatAmountString(value.amount, value.currency);
}

/**
 * Formats a raw decimal string such as "125000.50".
 *
 * Splits on the decimal point and groups the integer part by hand rather
 * than going through Number — a balance can exceed the range a double
 * represents exactly, and a display that disagrees with the ledger by a
 * kobo will be reported as a bug.
 */
export function formatAmountString(amount: string, currency = 'NGN'): string {
  const negative = amount.startsWith('-');
  const [major = '0', minor = '00'] = amount.replace('-', '').split('.');

  const grouped = major.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const symbol = currency === 'NGN' ? '₦' : `${currency} `;

  return `${negative ? '-' : ''}${symbol}${grouped}.${minor.padEnd(2, '0').slice(0, 2)}`;
}

/** Groups a bare account number for display: 0123 456 789. */
export function formatAccountNumber(value: string | null | undefined): string {
  if (!value) return '—';

  return value.replace(/\s+/g, '').replace(/(.{4})/g, '$1 ').trim();
}

/**
 * How the merchant is identified in the portal: their account number, falling
 * back to the onboarding reference for the (rare) window before an account
 * exists.
 */
export function merchantLabel(
  merchant:
    | {
        account?: { account_number_formatted?: string | null; account_number?: string | null } | null;
        merchant_number?: string | null;
      }
    | null
    | undefined,
): string {
  if (!merchant) return '—';

  return (
    merchant.account?.account_number_formatted ||
    (merchant.account?.account_number ? formatAccountNumber(merchant.account.account_number) : null) ||
    merchant.merchant_number ||
    '—'
  );
}

/** Day only — payment dates, due dates. */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '—';

  return format(parseISO(value), 'd MMM yyyy');
}

/** Day and time. */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—';

  return format(parseISO(value), 'd MMM yyyy, HH:mm');
}
