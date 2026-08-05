/** Mirrors App\Domains\Branches\Http\Resources\BranchResource. */

export type BranchStatusKey = 'active' | 'suspended' | 'closed';

export interface Branch {
  id: number;
  branch_code: string;
  name: string;
  label: string;

  address: string | null;
  city: string | null;
  state: string | null;
  country: string | null;

  phone: string | null;
  email: string | null;

  status: BranchStatusKey;
  status_label: string;
  accepts_new_business: boolean;

  manager: { id: number; staff_number: string; full_name: string } | null;
  staff_count?: number;

  opened_at: string | null;
  closed_at: string | null;

  created_at: string | null;
  updated_at: string | null;
}

export interface BranchFormInput {
  branch_code?: string;
  name: string;
  address?: string;
  city?: string;
  state?: string;
  country?: string;
  phone?: string;
  email?: string;
  opened_at?: string;
}

export interface BranchOption {
  value: number;
  label: string;
  branch_code: string;
}
