import type { MoneyValue } from '@naipay/shared-types';

/** Mirrors App\Domains\Reconciliation\Http\Resources\{BankReconciliationResource,BankStatementLineResource}. */

export type BankReconciliationStatusKey = 'in_progress' | 'pending_approval' | 'approved';
export type BankStatementLineStatusKey = 'unmatched' | 'matched' | 'excluded';
export type BankStatementLineDirectionKey = 'credit' | 'debit';

interface StaffSummary {
  id: number;
  staff_number: string;
  full_name: string;
}

export interface BankStatementLine {
  id: number;
  statement_date: string;
  description: string | null;
  external_reference: string | null;
  amount: MoneyValue;
  direction: BankStatementLineDirectionKey;
  direction_label: string;

  status: BankStatementLineStatusKey;
  status_label: string;

  matched_to: { type: 'repayment' | 'loan'; id: number; reference: string } | null;
  matched_at: string | null;

  excluded_reason: string | null;

  created_at: string | null;
}

export interface BankReconciliation {
  id: number;

  status: BankReconciliationStatusKey;
  status_label: string;
  allowed_transitions: BankReconciliationStatusKey[];

  bank_account: string | null;

  period_start: string;
  period_end: string;
  statement_opening_balance: MoneyValue;
  statement_closing_balance: MoneyValue;

  notes: string | null;

  lines: BankStatementLine[];
  has_unresolved_lines: boolean | null;

  prepared_by: StaffSummary | null;
  submitted_at: string | null;
  approved_by: StaffSummary | null;
  approved_at: string | null;

  created_at: string | null;
  updated_at: string | null;
}

export interface OpenReconciliationInput {
  bank_account_id: number;
  period_start: string;
  period_end: string;
  statement_opening_balance: string;
  statement_closing_balance: string;
  notes?: string;
}

export interface StatementLineInput {
  statement_date: string;
  description?: string;
  external_reference?: string;
  amount: string;
  direction: string;
}

export interface MatchSuggestion {
  id: number;
  reference: string;
}
