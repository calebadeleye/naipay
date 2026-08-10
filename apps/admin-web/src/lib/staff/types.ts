import type { MoneyValue } from '@naipay/shared-types';

/** Mirrors App\Domains\Staff\Http\Resources\StaffAdminResource. */

export type StaffStatusKey = 'pending_activation' | 'active' | 'suspended' | 'disabled';
export type AccessScopeKey = 'branch' | 'department' | 'global';

export interface StaffMember {
  id: number;
  staff_number: string;

  first_name: string;
  middle_name: string | null;
  last_name: string;
  full_name: string;
  initials: string;

  email: string;
  username: string | null;
  phone: string | null;
  job_title: string | null;
  department: string | null;

  status: StaffStatusKey;
  status_label: string;
  is_locked: boolean;
  locked_until: string | null;

  suspension_reason: string | null;
  suspended_at: string | null;

  access_scope: AccessScopeKey;
  access_scope_label: string;

  branch: { id: number; branch_code: string; name: string } | null;

  approval_limit: MoneyValue | null;
  has_approval_authority: boolean;

  roles: string[];

  two_factor: {
    enabled: boolean;
    required: boolean;
  };

  must_change_password: boolean;
  last_login_at: string | null;
  last_login_ip: string | null;

  created_at: string | null;
  updated_at: string | null;
}

export interface StaffFormInput {
  first_name: string;
  middle_name?: string;
  last_name: string;
  email: string;
  username?: string;
  phone?: string;
  job_title?: string;
  department?: string;
  branch_id?: number | string;
  access_scope?: string;
  roles?: string[];
}

export interface RoleOption {
  value: string;
  label: string;
  description: string;
}

/**
 * The 15 roles assignable through staff management — App\Domains\Identity\Enums\Role,
 * minus Super Administrator. There can only ever be one Super Administrator, seeded
 * once at install time, and neither the console nor the API allows granting it to
 * anyone else — so it's never offered here. See
 * StaffManagementService::ensureSingleSuperAdministrator() on the backend.
 */
export const ROLE_OPTIONS: RoleOption[] = [
  { value: 'executive', label: 'Executive', description: 'Portfolio-wide visibility and reporting, without operational write access.' },
  { value: 'operations-manager', label: 'Operations Manager', description: 'Oversees merchant onboarding, loan operations and branch activity.' },
  { value: 'branch-manager', label: 'Branch Manager', description: 'Manages a branch: its staff, merchants, loans and collections.' },
  { value: 'credit-manager', label: 'Credit Manager', description: 'Approves credit within limit and owns loan product configuration.' },
  { value: 'credit-officer', label: 'Credit Officer', description: 'Performs credit assessment and recommends applications for approval.' },
  { value: 'loan-officer', label: 'Loan Officer', description: 'Onboards merchants and originates loan applications.' },
  { value: 'finance-manager', label: 'Finance Manager', description: 'Owns the ledger, bank accounts, disbursement and reconciliation.' },
  { value: 'finance-officer', label: 'Finance Officer', description: 'Verifies repayments and performs day-to-day reconciliation.' },
  { value: 'cashier', label: 'Cashier', description: 'Records repayments received.' },
  { value: 'collections-officer', label: 'Collections Officer', description: 'Follows up overdue loans and manages collections.' },
  { value: 'compliance-officer', label: 'Compliance Officer', description: 'Verifies KYC and reviews documentation for compliance.' },
  { value: 'customer-support', label: 'Customer Support', description: 'Read access to merchant and loan records to answer enquiries.' },
  { value: 'auditor', label: 'Auditor', description: 'Read-only access across the system, including the audit trail.' },
  { value: 'read-only-user', label: 'Read-only User', description: 'Minimal read-only access.' },
  { value: 'investor-manager', label: 'Investor Manager', description: 'Adds and manages investor accounts and their access.' },
];
