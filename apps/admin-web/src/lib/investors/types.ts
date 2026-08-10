/** Mirrors App\Domains\Investors\Http\Resources\InvestorAdminResource. */

export type InvestorStatusKey = 'active' | 'suspended' | 'disabled';

export interface Investor {
  id: number;
  investor_number: string;

  name: string;
  initials: string;
  email: string;
  phone: string | null;

  status: InvestorStatusKey;
  status_label: string;
  is_locked: boolean;
  locked_until: string | null;
  failed_login_attempts: number;

  suspension_reason: string | null;
  suspended_at: string | null;

  created_by: { id: number; name: string } | null;

  last_login_at: string | null;
  last_login_ip: string | null;

  created_at: string | null;
  updated_at: string | null;
}

export interface InvestorFormInput {
  name: string;
  email: string;
  phone?: string;
}
