import type { MoneyValue } from '@naipay/shared-types';

/** Mirrors App\Domains\Loans\Http\Resources\LoanResource. */

export type LoanStatusKey = 'pending_approval' | 'pending_disbursement' | 'disbursed' | 'written_off';

interface StaffSummary {
  id: number;
  staff_number: string;
  full_name: string;
}

export interface LoanScheduleEntry {
  id: number;
  installment_number: number;
  due_date: string;
  opening_principal: string;
  principal_due: string;
  interest_due: string;
  fee_due: string;
  total_due: string;
  principal_paid: string;
  interest_paid: string;
  fee_paid: string;
  status: string;
  status_label: string;
}

export interface Loan {
  id: number;
  loan_reference: string;

  status: LoanStatusKey;
  status_label: string;
  allowed_transitions: LoanStatusKey[];

  terms: {
    principal_amount: MoneyValue;
    interest_method: string;
    interest_rate: string;
    repayment_frequency: string;
    tenor: number;
    grace_period_days: number;
  };

  totals: {
    total_interest: MoneyValue | null;
    total_fees: MoneyValue | null;
    total_payable: MoneyValue;
  } | null;

  outstanding: {
    principal: MoneyValue;
    interest: MoneyValue | null;
    fees: MoneyValue | null;
  } | null;

  disbursement: {
    bank_account: string | null;
    date: string | null;
    first_repayment_date: string | null;
    maturity_date: string | null;
    disbursed_by: StaffSummary | null;
    disbursed_at: string;
  } | null;

  write_off: {
    reason: string | null;
    written_off_by: StaffSummary | null;
    written_off_at: string;
  } | null;

  merchant: { id: number; merchant_number: string; full_name: string; email: string | null } | null;
  business: { id: number; business_number: string; business_name: string } | null;
  loan_product: { id: number; code: string; name: string } | null;
  loan_application_id: number | null;

  schedule: LoanScheduleEntry[];

  created_by: StaffSummary | null;
  approved_by: StaffSummary | null;

  created_at: string | null;
  updated_at: string | null;
}
