import type { MoneyValue } from '@naipay/shared-types';

/** Mirrors ReceiptResource. */
export interface Receipt {
  id: number;
  receipt_number: string;
  amount: MoneyValue;
  issued_at: string;

  repayment: { id: number; repayment_reference: string; payment_date: string; payment_method: string } | null;
  loan: { id: number; loan_reference: string } | null;
  business: { id: number; business_name: string } | null;
}
