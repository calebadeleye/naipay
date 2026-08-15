import type { MoneyValue } from '@naipay/shared-types';

/** Mirrors App\Domains\Businesses\Http\Resources\BusinessResource. */

export type BusinessVerificationStatusKey = 'unverified' | 'pending' | 'verified' | 'rejected';
export type BusinessStatusKey = 'active' | 'inactive' | 'suspended' | 'closed';

export interface BusinessCategoryRef {
  id: number;
  name: string;
}

export interface Business {
  id: number;
  business_number: string;
  merchant_id: number;

  business_name: string;
  registered_business_name: string | null;
  cac_registration_number: string | null;
  business_type: string;
  business_type_label: string;
  requires_cac_registration: boolean;
  has_outstanding_registration: boolean;

  business_description: string | null;

  category: BusinessCategoryRef | null;
  subcategory: BusinessCategoryRef | null;

  business_phone: string | null;
  business_email: string | null;
  business_address: string | null;
  city: string | null;
  state: string | null;
  country: string | null;
  business_website: string | null;
  social_media_links: Record<string, string> | null;

  gps_latitude: number | null;
  gps_longitude: number | null;

  year_established: number | null;
  number_of_employees: number | null;

  estimated_monthly_revenue: MoneyValue | null;
  estimated_monthly_expenses: MoneyValue | null;
  average_monthly_sales: MoneyValue | null;
  declared_monthly_surplus: MoneyValue | null;

  verification_status: BusinessVerificationStatusKey;
  verification_status_label: string;
  is_verified: boolean;

  status: BusinessStatusKey;
  status_label: string;
  is_operational: boolean;

  connections: {
    public_profile_enabled: boolean;
    accepts_business_connections: boolean;
    visibility: string;
  };

  approved_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface BusinessFormInput {
  business_name: string;
  registered_business_name?: string;
  cac_registration_number?: string;
  business_type: string;
  business_description?: string;
  business_category_id?: number | string;
  business_subcategory_id?: number | string;
  business_phone?: string;
  business_email?: string;
  business_address?: string;
  city?: string;
  state?: string;
  country?: string;
  business_website?: string;
  year_established?: number | string;
  number_of_employees?: number | string;
  estimated_monthly_revenue?: string;
  estimated_monthly_expenses?: string;
  average_monthly_sales?: string;
}
