/**
 * Identity contracts, mirroring the API's InvestorResource.
 */

export interface Investor {
  id: number;
  investor_number: string;
  name: string;
  initials: string;
  email: string;
  phone: string | null;
  status: string;
  status_label: string;
  last_login_at: string | null;
  created_at: string | null;
}

/** A completed sign-in. */
export interface InvestorSessionPayload {
  token: string;
  token_type: 'Bearer';
  expires_in: number;
  investor: Investor;
}
