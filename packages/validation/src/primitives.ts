import { z } from 'zod';

/**
 * Field-level rules shared by every Naipay frontend.
 *
 * These mirror the API's server-side validation so a form can reject bad input
 * before a round trip. They are a usability layer, never a security boundary —
 * the API validates every field again regardless of what the client checked.
 */

/**
 * An exact monetary amount, as a decimal string.
 *
 * Deliberately not a number: JavaScript cannot represent every kobo exactly,
 * and a rounded amount reaching the ledger is not recoverable. Forms bind to
 * the string and the API parses it into an exact decimal.
 */
export const moneyString = z
  .string()
  .trim()
  .regex(/^\d{1,15}(\.\d{1,2})?$/, 'Enter a valid amount, using at most two decimal places.');

export const positiveMoneyString = moneyString.refine(
  (value) => Number.parseFloat(value) > 0,
  'The amount must be greater than zero.',
);

/**
 * Nigerian mobile number.
 *
 * Accepts the local (0803…) and international (+234803…) forms operators
 * actually type; normalise with `toInternationalPhone` before submitting.
 */
export const nigerianPhone = z
  .string()
  .trim()
  .regex(
    /^(\+?234|0)[7-9][01]\d{8}$/,
    'Enter a valid Nigerian phone number, for example 08031234567.',
  );

export function toInternationalPhone(value: string): string {
  const digits = value.replace(/[^\d+]/g, '');

  if (digits.startsWith('+234')) return digits;
  if (digits.startsWith('234')) return `+${digits}`;
  if (digits.startsWith('0')) return `+234${digits.slice(1)}`;

  return digits;
}

/** Bank Verification Number — exactly 11 digits. */
export const bvn = z
  .string()
  .trim()
  .regex(/^\d{11}$/, 'A BVN is exactly 11 digits.');

/** National Identification Number — exactly 11 digits. */
export const nin = z
  .string()
  .trim()
  .regex(/^\d{11}$/, 'A NIN is exactly 11 digits.');

/**
 * Masks an identity number for display, showing only enough to confirm the
 * right record without exposing the value. Never reconstruct the full number
 * on the client — the API only sends the masked form to most roles.
 */
export function maskIdentityNumber(value: string): string {
  if (value.length < 4) return '*'.repeat(value.length);

  return `${value.slice(0, 2)}${'*'.repeat(value.length - 4)}${value.slice(-2)}`;
}

/** Corporate Affairs Commission registration number. */
export const cacRegistrationNumber = z
  .string()
  .trim()
  .regex(
    /^(RC|BN|IT|LP|LLP)?[-/ ]?\d{4,10}$/i,
    'Enter a valid CAC registration number, for example RC1234567.',
  );

/** Nigerian bank account number — 10 digits under the NUBAN standard. */
export const nubanAccountNumber = z
  .string()
  .trim()
  .regex(/^\d{10}$/, 'A Nigerian account number is exactly 10 digits.');

export const emailAddress = z
  .string()
  .trim()
  .toLowerCase()
  .email('Enter a valid email address.');

/**
 * Staff password policy, matching the API's rule: at least 12 characters with
 * mixed case, a digit and a symbol.
 */
export const staffPassword = z
  .string()
  .min(12, 'Use at least 12 characters.')
  .regex(/[a-z]/, 'Include at least one lowercase letter.')
  .regex(/[A-Z]/, 'Include at least one uppercase letter.')
  .regex(/\d/, 'Include at least one number.')
  .regex(/[^A-Za-z0-9]/, 'Include at least one symbol.');

/** A date the user supplies as YYYY-MM-DD. */
export const isoDate = z
  .string()
  .trim()
  .regex(/^\d{4}-\d{2}-\d{2}$/, 'Enter a date as YYYY-MM-DD.');

/**
 * A reason for a sensitive or destructive action.
 *
 * Required on reversals, write-offs, rejections and suspensions — the audit
 * log is only useful if the reason recorded is a real one, so a token
 * character or two is not enough.
 */
export const actionReason = z
  .string()
  .trim()
  .min(10, 'Give a reason of at least 10 characters. This is recorded in the audit log.')
  .max(1000, 'Keep the reason under 1000 characters.');
