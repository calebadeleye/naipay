import type { MoneyValue } from '@naipay/shared-types';

/** Mirrors App\Domains\LoanApplications\Http\Resources\LoanApplicationResource. */

export type LoanApplicationStatusKey =
  | 'draft'
  | 'submitted'
  | 'under_assessment'
  | 'recommended'
  | 'approved'
  | 'rejected'
  | 'withdrawn'
  | 'expired';

interface StaffSummary {
  id: number;
  staff_number: string;
  full_name: string;
}

export interface LoanApplication {
  id: number;
  application_number: string;

  status: LoanApplicationStatusKey;
  status_label: string;
  is_editable: boolean;
  is_pending_decision: boolean;
  allowed_transitions: LoanApplicationStatusKey[];

  requested: {
    amount: MoneyValue;
    tenor: number;
  };

  approved: {
    amount: MoneyValue | null;
    tenor: number | null;
    interest_rate: string | null;
  } | null;

  purpose: string | null;

  assessment: {
    notes: string | null;
    assessed_at: string | null;
    assessed_by: StaffSummary | null;
  };

  recommendation: {
    notes: string | null;
    recommended_at: string | null;
    recommended_by: StaffSummary | null;
  };

  decision_reason: string | null;
  withdrawal_reason: string | null;

  merchant: {
    id: number;
    merchant_number: string;
    full_name: string;
    can_borrow: boolean;
    account_number: string | null;
    account_number_formatted: string | null;
  } | null;

  business: {
    id: number;
    business_number: string;
    business_name: string;
    is_verified: boolean;
  } | null;

  loan_product: {
    id: number;
    code: string;
    name: string;
    requires_guarantor: boolean;
    minimum_guarantors: number;
    requires_collateral: boolean;
  } | null;

  guarantors: Guarantor[];
  guarantors_satisfied: boolean | null;

  branch: { id: number; branch_code: string; name: string } | null;
  created_by: StaffSummary | null;

  submitted_at: string | null;
  expires_at: string | null;

  created_at: string | null;
  updated_at: string | null;
}

export interface Guarantor {
  id: number;
  loan_application_id: number;
  full_name: string;
  phone: string;
  email: string | null;
  relationship: string;
  address: string | null;
  id_type: string | null;
  id_number: string | null;
  employer: string | null;
  occupation: string | null;
  monthly_income: MoneyValue | null;
  created_at: string | null;
}

export interface GuarantorFormInput {
  full_name: string;
  phone: string;
  email?: string;
  relationship: string;
  address?: string;
  id_type?: string;
  id_number?: string;
  employer?: string;
  occupation?: string;
  monthly_income?: string;
}

export interface LoanApplicationFormInput {
  merchant_id: number | null;
  business_id: number | null;
  loan_product_id: number | null;
  requested_amount: string;
  requested_tenor: number | string;
  purpose?: string;
}
