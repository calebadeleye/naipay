/** Mirrors App\Domains\Accounts\Http\Resources\BankAccountResource. */

export type BankAccountStatusKey = 'active' | 'suspended' | 'closed';
export type BankAccountPurposeKey =
  | 'loan_repayment_collection'
  | 'loan_disbursement'
  | 'operating_account'
  | 'settlement_account'
  | 'suspense_account'
  | 'other';

export interface BankAccount {
  id: number;
  bank_name: string;
  bank_code: string | null;
  account_name: string;
  account_number: string;
  account_number_formatted: string;
  branch_name: string | null;
  currency: string;

  account_purpose: BankAccountPurposeKey;
  account_purpose_label: string;

  is_default_collection_account: boolean;
  is_default_disbursement_account: boolean;

  status: BankAccountStatusKey;
  status_label: string;
  is_approved: boolean;
  can_transact: boolean;

  approved_by: string | null;
  approved_at: string | null;

  created_at: string | null;
  updated_at: string | null;
}

export interface BankAccountFormInput {
  bank_name: string;
  bank_code?: string;
  account_name: string;
  account_number: string;
  branch_name?: string;
  currency?: string;
  account_purpose: string;
}
