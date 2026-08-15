/**
 * Dashboard and report contracts, mirroring App\Domains\Reports\Services\ReportService.
 *
 * Every monetary figure here is a plain decimal string (e.g. "125000.50"),
 * not the full MoneyValue envelope other endpoints return — the report
 * service serialises with `toDecimalString()` rather than `jsonSerialize()`.
 * Render these with `formatAmountString`, not `formatMoney`.
 */

export type LoanApplicationStatusKey =
  | 'draft'
  | 'submitted'
  | 'under_assessment'
  | 'recommended'
  | 'approved'
  | 'rejected'
  | 'withdrawn'
  | 'expired';

export type LoanStatusKey =
  | 'pending_approval'
  | 'pending_disbursement'
  | 'disbursed'
  | 'written_off';

export interface PortfolioAtRisk {
  threshold_days: number;
  outstanding_principal: string;
  percentage_of_portfolio: number;
}

export interface DashboardSummary {
  merchants: {
    total: number;
    active: number;
    pending_onboarding: number;
  };
  loan_applications: Record<LoanApplicationStatusKey, number>;
  loans: Record<LoanStatusKey, number> & {
    total_outstanding_principal: string;
    total_capital_disbursed: string;
    total_expected_interest: string;
  };
  repayments: {
    pending_verification: number;
    pending_approval: number;
    collected_this_month: string;
  };
  portfolio_at_risk: PortfolioAtRisk;
}

export interface TrialBalanceAccount {
  code: string;
  name: string;
  type: 'asset' | 'liability' | 'equity' | 'income' | 'expense';
  total_debits: string;
  total_credits: string;
  balance: string;
}

export interface TrialBalance {
  as_of: string;
  accounts: TrialBalanceAccount[];
  total_debits: string;
  total_credits: string;
  is_balanced: boolean;
}

export interface LoanPortfolioByStatus {
  status: LoanStatusKey;
  count: number;
  outstanding_principal: string;
}

export interface LoanPortfolioByProduct {
  loan_product_id: number;
  name: string;
  count: number;
  outstanding_principal: string;
}

export interface LoanPortfolio {
  by_status: LoanPortfolioByStatus[];
  by_product: LoanPortfolioByProduct[];
  total_outstanding_principal: string;
}

export interface CollectionsReport {
  period: { from: string; to: string };
  count: number;
  total_collected: string;
  allocated_principal: string;
  allocated_interest: string;
  allocated_fee: string;
  allocated_excess: string;
  allocated_unallocated: string;
}

export interface AgeingBucket {
  label: string;
  count: number;
  outstanding_principal: string;
}

export interface DelinquencyReport {
  current: { count: number; outstanding_principal: string };
  ageing_buckets: AgeingBucket[];
  portfolio_at_risk: PortfolioAtRisk;
}

export interface PendingKycMerchant {
  id: number;
  merchant_number: string;
  full_name: string;
  kyc_status: string;
  days_waiting: number | null;
}

export interface PendingVerificationBusiness {
  id: number;
  business_name: string;
  days_waiting: number;
}

export interface ComplianceOverview {
  pending_kyc: { count: number; merchants: PendingKycMerchant[] };
  pending_business_verification: { count: number; businesses: PendingVerificationBusiness[] };
}
