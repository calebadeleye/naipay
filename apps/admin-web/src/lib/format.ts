import type { MoneyValue } from '@naipay/shared-types';
import { format, formatDistanceToNowStrict, parseISO } from 'date-fns';

/**
 * Presentation helpers.
 *
 * Timestamps arrive from the API in UTC and are rendered in the operator's
 * local timezone; amounts arrive as exact decimal strings and are never parsed
 * into a JavaScript number for anything other than display.
 */

const DISPLAY_LOCALE = 'en-NG';

/**
 * Formats an amount for display.
 *
 * Prefers the `formatted` string the API already produced, so the server stays
 * the single authority on how an amount reads. The fallback only runs for
 * amounts assembled on the client.
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
 * Splits on the decimal point and groups the integer part by hand rather than
 * going through Number: a portfolio total can exceed the range where a double
 * represents every kobo exactly, and a display that disagrees with the ledger
 * by a kobo will be reported as a bug.
 */
export function formatAmountString(amount: string, currency = 'NGN'): string {
  const negative = amount.startsWith('-');
  const [major = '0', minor = '00'] = amount.replace('-', '').split('.');

  const grouped = major.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const symbol = currency === 'NGN' ? '₦' : `${currency} `;

  return `${negative ? '-' : ''}${symbol}${grouped}.${minor.padEnd(2, '0').slice(0, 2)}`;
}

/** Compact form for dashboard cards: ₦1.78B, ₦34.6M. */
export function formatCompactMoney(amount: string, currency = 'NGN'): string {
  const value = Number.parseFloat(amount);

  if (!Number.isFinite(value)) {
    return formatAmountString(amount, currency);
  }

  const symbol = currency === 'NGN' ? '₦' : `${currency} `;
  const absolute = Math.abs(value);
  const sign = value < 0 ? '-' : '';

  const [divisor, suffix] =
    absolute >= 1e12 ? [1e12, 'T']
    : absolute >= 1e9 ? [1e9, 'B']
    : absolute >= 1e6 ? [1e6, 'M']
    : absolute >= 1e3 ? [1e3, 'K']
    : [1, ''];

  const scaled = absolute / divisor;
  const precision = suffix === '' ? 2 : scaled >= 100 ? 0 : scaled >= 10 ? 1 : 2;

  return `${sign}${symbol}${scaled.toFixed(precision)}${suffix}`;
}

/** Day only — payment dates, due dates, dates of birth. */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '—';

  return format(parseISO(value), 'd MMM yyyy');
}

/** Day and time, for audit entries and activity timelines. */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—';

  return format(parseISO(value), 'd MMM yyyy, HH:mm');
}

/** "3 days ago" — for activity feeds, never for financial figures. */
export function formatRelative(value: string | null | undefined): string {
  if (!value) return '—';

  return `${formatDistanceToNowStrict(parseISO(value))} ago`;
}

export function formatNumber(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—';

  return new Intl.NumberFormat(DISPLAY_LOCALE).format(value);
}

export function formatPercentage(value: number | null | undefined, fractionDigits = 1): string {
  if (value === null || value === undefined) return '—';

  return `${value.toFixed(fractionDigits)}%`;
}

/**
 * Masks an identity number for display: 22*******14.
 *
 * The API only sends the masked form to most roles; this exists for the few
 * that receive the full value and must still not render it in a list.
 */
export function maskIdentityNumber(value: string | null | undefined): string {
  if (!value) return '—';
  if (value.length < 4) return '*'.repeat(value.length);

  return `${value.slice(0, 2)}${'*'.repeat(value.length - 4)}${value.slice(-2)}`;
}

/** Groups a bare account number for display: 0123 456 789. */
export function formatAccountNumber(value: string | null | undefined): string {
  if (!value) return '—';

  return value.replace(/\s+/g, '').replace(/(.{4})/g, '$1 ').trim();
}

/**
 * How a merchant is identified in the UI: their generated account number,
 * falling back to the `NPM-` onboarding reference for merchants not yet
 * approved (who have no account).
 */
export function merchantLabel(
  merchant:
    | {
        account_number_formatted?: string | null;
        account_number?: string | null;
        merchant_number?: string | null;
        account?: { account_number_formatted?: string | null; account_number?: string | null } | null;
      }
    | null
    | undefined,
): string {
  if (!merchant) return '—';

  const formatted = merchant.account_number_formatted ?? merchant.account?.account_number_formatted ?? null;
  const bare = merchant.account_number ?? merchant.account?.account_number ?? null;

  return formatted || (bare ? formatAccountNumber(bare) : null) || merchant.merchant_number || '—';
}
