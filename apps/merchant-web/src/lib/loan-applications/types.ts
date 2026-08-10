import type { MoneyValue } from '@naipay/shared-types';

export type LoanApplicationStatusKey =
  | 'draft'
  | 'submitted'
  | 'under_assessment'
  | 'recommended'
  | 'approved'
  | 'rejected'
  | 'withdrawn'
  | 'expired';

/** Mirrors LoanApplicationSelfResource. */
export interface LoanApplication {
  id: number;
  application_number: string;

  status: LoanApplicationStatusKey;
  status_label: string;
  is_editable: boolean;

  requested: { amount: MoneyValue; tenor: number };
  approved: { amount: MoneyValue | null; tenor: number | null; interest_rate: string | null } | null;

  purpose: string | null;
  decision_reason: string | null;
  withdrawal_reason: string | null;

  business: { id: number; business_number: string; business_name: string } | null;
  loan_product: { id: number; name: string; requires_guarantor: boolean; minimum_guarantors: number } | null;

  guarantors: unknown[];
  guarantors_satisfied: boolean | null;

  submitted_at: string | null;
  expires_at: string | null;
  created_at: string | null;
}

export interface LoanApplicationFormInput {
  business_id: number | string;
  loan_product_id: number | string;
  requested_amount: string;
  requested_tenor: number | string;
  purpose?: string;
}
