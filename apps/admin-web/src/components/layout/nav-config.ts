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
  FileCheck2,
  History,
  Building2,
  UserCog,
} from 'lucide-react';

export interface NavItem {
  label: string;
  href: string;
  icon: LucideIcon;
  /**
   * The permission that gates the API routes this screen calls. Absent
   * (or not held by the signed-in staff member) means the item is not
   * rendered at all — see AppShell's filtering — rather than rendered and
   * left to fail with a 403 once clicked.
   */
  permission: string;
}

export interface NavSection {
  label: string;
  items: NavItem[];
}

/**
 * Navigation for what actually exists today.
 *
 * Every domain phase 0 through 16 now has a screen. Add an entry here the
 * moment a new route lands, not before — a dead link in a financial console
 * reads as broken, not as "coming soon". Each item's `permission` mirrors
 * the `permission:` middleware on that screen's underlying API route(s), so
 * the two only ever need to be kept in sync in one direction: the routes are
 * the source of truth.
 */
export const navSections: NavSection[] = [
  {
    label: 'Overview',
    items: [{ label: 'Dashboard', href: '/', icon: LayoutDashboard, permission: 'dashboard.view' }],
  },
  {
    label: 'Merchants',
    items: [
      { label: 'Merchants', href: '/merchants', icon: Users, permission: 'merchants.view' },
      { label: 'Businesses', href: '/businesses', icon: Store, permission: 'businesses.view' },
    ],
  },
  {
    label: 'Lending',
    items: [
      { label: 'Loan products', href: '/loan-products', icon: Package, permission: 'loan_products.view' },
      {
        label: 'Loan applications',
        href: '/loan-applications',
        icon: FileText,
        permission: 'loan_applications.view',
      },
      { label: 'Loans', href: '/loans', icon: Landmark, permission: 'loans.view' },
      { label: 'Repayments', href: '/repayments', icon: Receipt, permission: 'repayments.view' },
      { label: 'Receipts', href: '/receipts', icon: FileCheck2, permission: 'repayments.view' },
    ],
  },
  {
    label: 'Finance',
    items: [
      { label: 'Bank accounts', href: '/bank-accounts', icon: Banknote, permission: 'bank_accounts.view' },
      { label: 'Ledger', href: '/ledger', icon: BookOpen, permission: 'ledger.view' },
      { label: 'Reconciliation', href: '/reconciliation', icon: ListChecks, permission: 'reconciliation.view' },
    ],
  },
  {
    label: 'Reports',
    items: [
      { label: 'Trial balance', href: '/reports/trial-balance', icon: Scale, permission: 'reports.financial' },
      { label: 'Loan portfolio', href: '/reports/loan-portfolio', icon: PieChart, permission: 'reports.view' },
      { label: 'Collections', href: '/reports/collections', icon: Wallet, permission: 'reports.view' },
      { label: 'Delinquency', href: '/reports/delinquency', icon: TrendingDown, permission: 'reports.view' },
      { label: 'Compliance', href: '/reports/compliance', icon: ShieldCheck, permission: 'audit.view' },
    ],
  },
  {
    label: 'Compliance',
    items: [{ label: 'Audit log', href: '/audit-log', icon: History, permission: 'audit.view' }],
  },
  {
    label: 'Organisation',
    items: [
      { label: 'Branches', href: '/branches', icon: Building2, permission: 'branches.view' },
      { label: 'Staff', href: '/staff', icon: UserCog, permission: 'staff.view' },
    ],
  },
];

/**
 * `navSections` filtered to what `permissions` actually allows — a section
 * that loses every item is dropped too, rather than left as an empty
 * heading with nothing under it.
 */
export function visibleNavSections(permissions: readonly string[]): NavSection[] {
  const granted = new Set(permissions);

  return navSections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => granted.has(item.permission)),
    }))
    .filter((section) => section.items.length > 0);
}
