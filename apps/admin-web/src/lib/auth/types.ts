/**
 * Identity contracts, mirroring the API's StaffResource.
 */

export type RequiredAction = 'change_password' | null;

export interface StaffTwoFactorState {
  enabled: boolean;
  /** True where the account's role or permissions make 2FA mandatory. */
  required: boolean;
  /** Mandatory for this account but not yet set up — nudged, not enforced. */
  setup_pending: boolean;
  confirmed_at: string | null;
}

export interface Staff {
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
  status: string;
  status_label: string;
  roles: string[];
  /**
   * Flattened across roles. Drives navigation and action visibility only —
   * the API re-checks every permission server-side regardless.
   */
  permissions: string[];
  two_factor: StaffTwoFactorState;
  must_change_password: boolean;
  password_changed_at: string | null;
  last_login_at: string | null;
  last_login_ip: string | null;
}

/** A completed sign-in. */
export interface SessionPayload {
  token: string;
  token_type: 'Bearer';
  expires_in: number;
  staff: Staff;
  required_action: RequiredAction;
}

/** A sign-in that cleared the first factor and awaits a code. */
export interface TwoFactorChallengePayload {
  requires_two_factor: true;
  challenge_token: string;
  expires_in: number;
}

export type LoginResult = SessionPayload | TwoFactorChallengePayload;

export function isTwoFactorChallenge(result: LoginResult): result is TwoFactorChallengePayload {
  return 'requires_two_factor' in result && result.requires_two_factor === true;
}
