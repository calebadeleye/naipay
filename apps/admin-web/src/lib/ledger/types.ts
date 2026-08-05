import type { MoneyValue } from '@naipay/shared-types';

/** Mirrors App\Domains\Ledger\Http\Resources\JournalTransactionResource and LedgerAccountResource. */

export type JournalTransactionStatusKey = 'posted' | 'reversed';
export type AccountTypeKey = 'asset' | 'liability' | 'equity' | 'income' | 'expense';

export interface JournalEntryLine {
  account_code: string;
  account_name: string;
  debit_amount: MoneyValue;
  credit_amount: MoneyValue;
  description: string | null;
  loan_id: number | null;
  merchant_id: number | null;
}

export interface JournalTransaction {
  id: number;
  transaction_reference: string;
  transaction_type: string;
  description: string | null;
  currency: string;

  source_type: string | null;
  source_id: number | null;

  transaction_date: string;
  posting_date: string;

  status: JournalTransactionStatusKey;
  status_label: string;
  is_reversal: boolean;
  reversal_of_id: number | null;
  reversed_by_id: number | null;
  reversed_at: string | null;

  created_by: string | null;
  entries: JournalEntryLine[] | null;

  created_at: string | null;
}

export interface LedgerAccount {
  id: number;
  code: string;
  name: string;
  description: string | null;
  type: AccountTypeKey;
  type_label: string;
  is_system: boolean;
  status: string;
  is_active: boolean;
  balance: MoneyValue;
}
