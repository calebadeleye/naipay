/**
 * Contracts for the investor dashboard, mirroring
 * InvestorDashboardService::summary().
 */

export interface RevenueProfitPoint {
  period: string;
  label: string;
  revenue: string;
  net_profit: string;
}

export interface PortfolioAllocationSlice {
  name: string;
  outstanding_principal: string;
  percentage: number;
}

export interface BranchPerformance {
  branch_id: number;
  name: string;
  branch_code: string | null;
  credit_portfolio: string;
  loan_count: number;
  growth_mtd_percentage: number;
}

export interface RecentRepayment {
  id: number;
  merchant_name: string;
  amount: string;
  payment_date: string | null;
}

export interface InvestorDashboard {
  as_of: string;

  total_investment_portfolio: string;
  total_revenue_mtd: string;
  net_profit_mtd: string;
  roi_ytd_percentage: number;
  number_of_debtors: number;

  active_loans: number;
  outstanding_loan_balance: string;
  collections_this_month: string;
  default_rate_percentage: number;
  portfolio_growth_ytd_percentage: number;

  revenue_and_profit_trend: RevenueProfitPoint[];
  portfolio_allocation: PortfolioAllocationSlice[];
  top_performing_branches: BranchPerformance[];
  recent_repayments: RecentRepayment[];
}
