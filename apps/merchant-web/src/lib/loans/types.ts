import type { MoneyValue } from '@naipay/shared-types';

export type LoanStatusKey = 'pending_approval' | 'pending_disbursement' | 'disbursed' | 'written_off';

export interface LoanScheduleEntry {
  id: number;
  installment_number: number;
  due_date: string;
  principal_due: MoneyValue;
  interest_due: MoneyValue;
  fee_due: MoneyValue;
  status: string;
  status_label: string;
}

/** Mirrors LoanResource. */
export interface Loan {
  id: number;
  loan_reference: string;

  status: LoanStatusKey;
  status_label: string;

  terms: {
    principal_amount: MoneyValue;
    interest_method: string;
    interest_rate: string;
    repayment_frequency: string;
    tenor: number;
    grace_period_days: number;
  };

  totals: { total_interest: MoneyValue | null; total_fees: MoneyValue | null; total_payable: MoneyValue } | null;
  outstanding: { principal: MoneyValue; interest: MoneyValue | null; fees: MoneyValue | null } | null;

  disbursement: {
    bank_account: string | null;
    date: string | null;
    first_repayment_date: string | null;
    maturity_date: string | null;
    disbursed_at: string;
  } | null;

  write_off: { reason: string | null; written_off_at: string } | null;

  business: { id: number; business_number: string; business_name: string } | null;
  loan_product: { id: number; code: string; name: string } | null;

  loan_application_id: number | null;

  schedule: LoanScheduleEntry[];

  created_at: string | null;
}
