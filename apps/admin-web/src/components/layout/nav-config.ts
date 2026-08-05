import type { LucideIcon } from 'lucide-react';
import {
  LayoutDashboard,
  Scale,
  PieChart,
  Wallet,
  TrendingDown,
  ShieldCheck,
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
 * Every other domain phase 2 onward — merchants, loan applications, loans,
 * repayments, bank accounts, the ledger, reconciliation, receipts, the audit
 * log — is deliberately absent rather than linked as a placeholder: a dead
 * link in a financial console reads as broken, not as "coming soon".
 */
export const navSections: NavSection[] = [
  {
    label: 'Overview',
    items: [{ label: 'Dashboard', href: '/', icon: LayoutDashboard }],
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
