/**
 * Naipay design tokens.
 *
 * The visual direction is a light, trustworthy financial interface: green as
 * the brand and primary action colour, a near-black slate for text and
 * navigation, and status colours that stay legible on the dense data tables
 * that make up most of the administrative surface.
 *
 * Semantic status colours are deliberately not the same green as the brand —
 * an operator scanning a table needs "approved" to read as a status, not as a
 * branded element.
 */

export const brand = {
  /** Primary Naipay green. Used for primary actions and active navigation. */
  50: '#eefbf3',
  100: '#d6f5e3',
  200: '#b0e9cb',
  300: '#7dd7ac',
  400: '#47bc89',
  500: '#22a06b',
  600: '#158055',
  700: '#126647',
  800: '#12513a',
  900: '#0f4331',
  950: '#04261c',
} as const;

/** Neutral slate used for text, borders, surfaces and the sidebar. */
export const neutral = {
  0: '#ffffff',
  50: '#f8fafc',
  100: '#f1f5f9',
  200: '#e2e8f0',
  300: '#cbd5e1',
  400: '#94a3b8',
  500: '#64748b',
  600: '#475569',
  700: '#334155',
  800: '#1e293b',
  900: '#0f172a',
  950: '#020617',
} as const;

/**
 * Status colours for loan, repayment, KYC and approval states.
 *
 * Colour is never the only signal — every status badge pairs these with a
 * label, so the interface remains usable for colour-blind operators.
 */
export const status = {
  success: '#15803d',
  successSurface: '#dcfce7',
  warning: '#b45309',
  warningSurface: '#fef3c7',
  danger: '#b91c1c',
  dangerSurface: '#fee2e2',
  info: '#1d4ed8',
  infoSurface: '#dbeafe',
  neutral: '#475569',
  neutralSurface: '#f1f5f9',
} as const;

/**
 * Monetary and quantitative emphasis.
 *
 * Credits and debits are shown in distinct colours on the ledger and
 * reconciliation screens, where the sign alone is easy to misread.
 */
export const financial = {
  credit: '#15803d',
  debit: '#b91c1c',
  /** Overdue and past-due amounts across schedules and ageing reports. */
  overdue: '#c2410c',
} as const;

export const typography = {
  /** Interface text. */
  sans: "'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif",
  /**
   * Monetary amounts, references and account numbers. Tabular figures keep
   * columns of amounts aligned, which matters on every financial table.
   */
  mono: "'JetBrains Mono', ui-monospace, 'SF Mono', Menlo, monospace",
} as const;

export const radius = {
  sm: '0.25rem',
  md: '0.375rem',
  lg: '0.5rem',
  xl: '0.75rem',
} as const;

export const tokens = { brand, neutral, status, financial, typography, radius } as const;

export default tokens;
