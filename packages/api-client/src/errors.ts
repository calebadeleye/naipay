import type { ApiFieldErrors } from '@naipay/shared-types';

/**
 * A failure returned by the Naipay API, carrying enough detail for the caller
 * to decide between showing field errors, prompting re-authentication, or
 * surfacing a support reference.
 */
export class ApiError extends Error {
  readonly status: number;
  readonly errors: ApiFieldErrors;
  /** Echoed from the response so a user can quote it to support. */
  readonly correlationId: string | null;

  constructor(
    message: string,
    status: number,
    errors: ApiFieldErrors = {},
    correlationId: string | null = null,
  ) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
    this.correlationId = correlationId;

    // Required for `instanceof` to work when targeting ES5-era output.
    Object.setPrototypeOf(this, ApiError.prototype);
  }

  /** The session is gone or the token expired; the client should sign in again. */
  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  /** Authenticated, but lacking the permission for this action. */
  get isForbidden(): boolean {
    return this.status === 403;
  }

  get isValidation(): boolean {
    return this.status === 422 && Object.keys(this.errors).length > 0;
  }

  /**
   * A business rule refused the operation — an already-approved repayment, a
   * maker-checker violation. Distinct from a validation failure: the request
   * was well-formed, the state was wrong.
   */
  get isConflict(): boolean {
    return this.status === 409;
  }

  get isRateLimited(): boolean {
    return this.status === 429;
  }

  get isServerError(): boolean {
    return this.status >= 500;
  }

  /** First message for a field, for binding to a form control. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0];
  }
}

/**
 * Raised when the request never reached the API — offline, DNS failure,
 * timeout. Kept separate from ApiError so the UI can distinguish "we could not
 * reach Naipay" from "Naipay refused this".
 */
export class NetworkError extends Error {
  readonly cause?: unknown;

  constructor(message: string, cause?: unknown) {
    super(message);
    this.name = 'NetworkError';
    this.cause = cause;
    Object.setPrototypeOf(this, NetworkError.prototype);
  }
}
