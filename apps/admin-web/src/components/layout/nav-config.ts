import type { LucideIcon } from 'lucide-react';
import {
  LayoutDashboard,
  Scale,
  PieChart,
  Wallet,
  TrendingDown,
  ShieldCheck,
  Users,
  Store,
  Package,
  FileText,
  Landmark,
  Receipt,
  Banknote,
  BookOpen,
  ListChecks,
} from 'lucide-react';

export interface NavItem {
  label: string;
  href: string;
  icon: LucideIcon;
}

export interface NavSection {
  label: string;
  items: NavItem[];
}

/**
 * Navigation for what actually exists today.
 *
 * Every domain still without a screen — loan applications, loans,
 * repayments, bank accounts, the ledger, reconciliation, receipts, the audit
 * log — is deliberately absent rather than linked as a placeholder: a dead
 * link in a financial console reads as broken, not as "coming soon". Add an
 * entry here the moment its route lands, not before.
 */
export const navSections: NavSection[] = [
  {
    label: 'Overview',
    items: [{ label: 'Dashboard', href: '/', icon: LayoutDashboard }],
  },
  {
    label: 'Merchants',
    items: [
      { label: 'Merchants', href: '/merchants', icon: Users },
      { label: 'Businesses', href: '/businesses', icon: Store },
    ],
  },
  {
    label: 'Lending',
    items: [
      { label: 'Loan products', href: '/loan-products', icon: Package },
      { label: 'Loan applications', href: '/loan-applications', icon: FileText },
      { label: 'Loans', href: '/loans', icon: Landmark },
      { label: 'Repayments', href: '/repayments', icon: Receipt },
    ],
  },
  {
    label: 'Finance',
    items: [
      { label: 'Bank accounts', href: '/bank-accounts', icon: Banknote },
      { label: 'Ledger', href: '/ledger', icon: BookOpen },
      { label: 'Reconciliation', href: '/reconciliation', icon: ListChecks },
    ],
  },
  {
    label: 'Reports',
    items: [
      { label: 'Trial balance', href: '/reports/trial-balance', icon: Scale },
      { label: 'Loan portfolio', href: '/reports/loan-portfolio', icon: PieChart },
      { label: 'Collections', href: '/reports/collections', icon: Wallet },
      { label: 'Delinquency', href: '/reports/delinquency', icon: TrendingDown },
      { label: 'Compliance', href: '/reports/compliance', icon: ShieldCheck },
    ],
  },
];
