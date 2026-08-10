import type { MoneyValue } from '@naipay/shared-types';

/** Mirrors RepaymentSummaryController's response shape. */
export interface RepaymentSummary {
  outstanding_balance: MoneyValue;
  active_loan_count: number;
  next_due: {
    loan_id: number;
    loan_reference: string;
    due_date: string;
    amount: MoneyValue;
    status: string;
  } | null;
  pay_into: {
    bank_name: string;
    account_name: string;
    account_number: string;
  } | null;
}
