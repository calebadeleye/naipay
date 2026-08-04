/**
 * The Naipay API envelope.
 *
 * Every endpoint returns one of these two shapes, so a client can decide how
 * to handle a response by reading `success` alone — before it knows anything
 * about the endpoint it called.
 */

/** Pagination details, lifted into `meta` for any list endpoint. */
export interface PaginationMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
  from: number | null;
  to: number | null;
  has_more_pages: boolean;
}

export interface ResponseMeta {
  pagination?: PaginationMeta;
  [key: string]: unknown;
}

export interface ApiSuccess<TData> {
  success: true;
  message: string;
  data: TData;
  meta: ResponseMeta;
}

/**
 * Field-level failures. Keyed by field name, each holding every message for
 * that field — the shape Laravel's validator produces and the admin forms
 * bind directly to.
 */
export type ApiFieldErrors = Record<string, string[]>;

export interface ApiFailure {
  success: false;
  message: string;
  /** Present only for validation failures; absent for every other error. */
  errors?: ApiFieldErrors;
}

export type ApiEnvelope<TData> = ApiSuccess<TData> | ApiFailure;

/** A paginated list response, with the items already unwrapped. */
export interface PaginatedResult<TItem> {
  items: TItem[];
  pagination: PaginationMeta;
}

/**
 * An exact monetary amount as the API serialises it.
 *
 * `amount` is the authoritative value and is always a decimal string — never
 * parse it into a JavaScript number for arithmetic, because IEEE-754 cannot
 * represent every kobo exactly. Use `minor_units` if you must compute in the
 * client, and `formatted` for display.
 */
export interface MoneyValue {
  amount: string;
  minor_units: number;
  currency: string;
  formatted: string;
}

/** Query parameters accepted by every list endpoint. */
export interface ListQuery {
  search?: string;
  sort?: string;
  page?: number;
  per_page?: number;
  [filter: string]: string | number | boolean | undefined;
}
