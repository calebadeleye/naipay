import type { MoneyValue } from '@naipay/shared-types';

/** Mirrors App\Domains\LoanProducts\Http\Resources\LoanProductResource. */

export type TenorUnitKey = 'days' | 'weeks' | 'months';
export type RepaymentFrequencyKey = 'daily' | 'weekly' | 'monthly';
export type InterestMethodKey = 'flat' | 'reducing_balance' | 'declining_balance' | 'simple_interest';
export type FeeTypeKey = 'none' | 'fixed' | 'percentage';
export type LoanProductStatus = 'active' | 'retired';

export interface LoanProduct {
  id: number;
  code: string;
  name: string;
  description: string | null;
  summary: string;

  amount: {
    minimum: MoneyValue;
    maximum: MoneyValue;
  };

  tenor: {
    minimum: number;
    maximum: number;
    default: number | null;
    unit: TenorUnitKey;
    unit_label: string;
  };

  interest: {
    rate: string;
    method: InterestMethodKey;
    method_label: string;
    method_description: string;
    varies_with_term: boolean;
    period: string | null;
  };

  repayment: {
    frequency: RepaymentFrequencyKey;
    frequency_label: string;
    skips_weekends: boolean;
    grace_period_days: number;
  };

  fees: {
    processing: { type: FeeTypeKey; value: MoneyValue | null };
    insurance: { type: FeeTypeKey; value: MoneyValue | null };
    late_payment_penalty: { type: FeeTypeKey; value: MoneyValue | null };
  };

  requirements: {
    requires_guarantor: boolean;
    minimum_guarantors: number;
    requires_collateral: boolean;
  };

  status: LoanProductStatus;
  is_active: boolean;
  display_order: number;

  created_at: string | null;
  updated_at: string | null;
}

export interface LoanProductFormInput {
  code: string;
  name: string;
  description?: string;
  minimum_amount: string;
  maximum_amount: string;
  minimum_tenor: number | string;
  maximum_tenor: number | string;
  default_tenor?: number | string;
  tenor_unit: string;
  interest_method: string;
  interest_rate: string;
  interest_period?: string;
  repayment_frequency: string;
  processing_fee_type: string;
  processing_fee_value?: string;
  insurance_fee_type: string;
  insurance_fee_value?: string;
  late_payment_penalty_type: string;
  late_payment_penalty_value?: string;
  grace_period_days: number | string;
  requires_guarantor: boolean;
  minimum_guarantors: number | string;
  requires_collateral: boolean;
  display_order: number | string;
}

export interface Instalment {
  installment_number: number;
  due_date: string;
  opening_principal: string;
  principal_due: string;
  interest_due: string;
  fee_due: string;
  total_due: string;
  closing_principal: string;
}

export interface SchedulePreview {
  summary: {
    principal: MoneyValue;
    total_interest: MoneyValue;
    total_fees: MoneyValue;
    total_payable: MoneyValue;
    instalment_count: number;
    first_repayment_date: string;
    maturity_date: string;
    net_disbursement: MoneyValue;
  };
  schedule: Instalment[];
}
