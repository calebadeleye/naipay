import type { MoneyValue } from '@naipay/shared-types';

/** Mirrors App\Domains\Merchants\Http\Resources\MerchantResource. */

export type OnboardingStatusKey =
  | 'draft'
  | 'submitted'
  | 'pending_verification'
  | 'pending_approval'
  | 'approved'
  | 'rejected'
  | 'suspended'
  | 'closed';

export type MerchantStatusKey = 'inactive' | 'active' | 'dormant' | 'suspended' | 'closed';
export type KycStatusKey = 'not_started' | 'pending' | 'verified' | 'rejected' | 'expired';
export type RiskRatingKey = 'low' | 'moderate' | 'high' | 'very_high';

export interface MerchantSummary {
  id: number;
  branch_id?: number;
}

export interface Merchant {
  id: number;
  merchant_number: string;

  first_name: string;
  middle_name: string | null;
  last_name: string;
  full_name: string;

  date_of_birth: string | null;
  gender: string | null;
  marital_status: string | null;
  employment_status: string | null;
  preferred_language: string | null;

  phone: string;
  alternative_phone: string | null;
  email: string | null;

  residential_address: string | null;
  city: string | null;
  state: string | null;
  country: string | null;

  identity: {
    bvn_masked: string | null;
    nin_masked: string | null;
    has_bvn: boolean;
    has_nin: boolean;
    bvn: string | null;
    nin: string | null;
    unmasked: boolean;
  };

  profile_photo: string | null;

  onboarding_status: OnboardingStatusKey;
  onboarding_status_label: string;
  is_editable: boolean;
  allowed_transitions: OnboardingStatusKey[];

  merchant_status: MerchantStatusKey;
  merchant_status_label: string;

  kyc_status: KycStatusKey;
  kyc_status_label: string;

  risk_rating: RiskRatingKey | null;
  risk_rating_label: string | null;

  can_borrow: boolean;

  rejection_reason: string | null;
  suspension_reason: string | null;

  branch: { id: number; branch_code: string; name: string } | null;
  assigned_officer: { id: number; staff_number: string; full_name: string } | null;

  account: {
    id: number;
    account_number: string;
    account_number_formatted: string;
    account_name: string;
    currency: string;
    status: string;
    available_balance: MoneyValue | null;
  } | null;

  businesses?: import('@/lib/businesses/types').Business[];
  businesses_count?: number;

  submitted_at: string | null;
  verified_at: string | null;
  approved_at: string | null;

  created_at: string | null;
  updated_at: string | null;
}

export interface MerchantFormInput {
  first_name: string;
  middle_name?: string;
  last_name: string;
  date_of_birth?: string;
  gender?: string;
  phone: string;
  alternative_phone?: string;
  email?: string;
  residential_address?: string;
  city?: string;
  state?: string;
  country?: string;
  bvn?: string;
  nin?: string;
}
