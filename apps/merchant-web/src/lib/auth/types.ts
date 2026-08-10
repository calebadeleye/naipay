/**
 * Identity contracts, mirroring the API's MerchantSelfResource.
 */

export interface MerchantAccount {
  account_number: string;
  account_number_formatted: string;
  account_name: string;
  currency: string;
  available_balance: { amount: string; formatted: string; currency: string } | null;
}

export interface Business {
  id: number;
  business_number: string;
  business_name: string;
  business_type: string;
  business_type_label: string;
  status: string;
  status_label?: string;
  verification_status: string;
  is_verified?: boolean;
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

  phone: string | null;
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
  };

  profile_photo: string | null;

  onboarding_status: string;
  onboarding_status_label: string;
  merchant_status: string;
  merchant_status_label: string;
  kyc_status: string;
  kyc_status_label: string;

  can_borrow: boolean;
  rejection_reason: string | null;
  suspension_reason: string | null;

  account: MerchantAccount | null;
  businesses: Business[];

  activated_at: string | null;
  last_login_at: string | null;
  created_at: string | null;
}

/** A completed sign-in. */
export interface MerchantSessionPayload {
  token: string;
  token_type: 'Bearer';
  expires_in: number;
  merchant: Merchant;
}
