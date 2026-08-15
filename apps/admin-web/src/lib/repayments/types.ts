import type { MoneyValue } from '@naipay/shared-types';

/** Mirrors App\Domains\Repayments\Http\Resources\RepaymentResource. */

export type RepaymentStatusKey = 'recorded' | 'verified' | 'approved' | 'rejected' | 'reversed';
export type PaymentMethodKey = 'bank_transfer' | 'cash' | 'pos' | 'other';

interface StaffSummary {
  id: number;
  staff_number: string;
  full_name: string;
}

export interface RepaymentAllocationEntry {
  loan_schedule_entry_id: number;
  installment_number: number | null;
  principal_amount: string;
  interest_amount: string;
  fee_amount: string;
}

export interface Repayment {
  id: number;
  repayment_reference: string;

  status: RepaymentStatusKey;
  status_label: string;
  allowed_transitions: RepaymentStatusKey[];

  amount: MoneyValue;
  payment_date: string;
  payment_method: PaymentMethodKey;
  payment_method_label: string;

  sender_account_name: string | null;
  sender_account_number: string | null;
  sender_bank_name: string | null;
  bank_reference: string | null;
  notes: string | null;

  allocation: {
    principal: MoneyValue;
    interest: MoneyValue | null;
    fee: MoneyValue | null;
    excess: MoneyValue | null;
    unallocated: MoneyValue | null;
    entries: RepaymentAllocationEntry[];
  } | null;

  loan: { id: number; loan_reference: string; status: string } | null;
  merchant: { id: number; merchant_number: string; full_name: string } | null;
  receiving_bank_account: string | null;

  verification: { notes: string | null; verified_by: StaffSummary | null; verified_at: string } | null;
  rejection: { reason: string | null; rejected_by: StaffSummary | null; rejected_at: string } | null;
  reversal: { reason: string | null; reversed_by: StaffSummary | null; reversed_at: string } | null;

  recorded_by: StaffSummary | null;
  approved_by: StaffSummary | null;

  created_at: string | null;
  updated_at: string | null;
}

export interface RepaymentFormInput {
  loan_id: number;
  receiving_bank_account_id: number;
  amount: string;
  payment_date: string;
  payment_method: string;
  sender_account_name?: string;
  sender_account_number?: string;
  sender_bank_name?: string;
  bank_reference?: string;
  notes?: string;
  confirm_duplicate?: boolean;
}
