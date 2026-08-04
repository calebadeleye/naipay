import type {
  ApiEnvelope,
  ApiSuccess,
  ListQuery,
  PaginatedResult,
} from '@naipay/shared-types';

import { ApiError, NetworkError } from './errors';

const CORRELATION_HEADER = 'X-Correlation-Id';

export interface TokenStore {
  get(): string | null | Promise<string | null>;
  clear(): void | Promise<void>;
}

export interface ApiClientOptions {
  /** Base URL including the version prefix, e.g. https://naipay.naitalk.com/api/v1 */
  baseUrl: string;
  tokenStore?: TokenStore;
  /** Abandon a request after this many milliseconds. */
  timeoutMs?: number;
  /**
   * Invoked when the API reports the session is no longer valid, so the host
   * app can redirect to sign-in. Called once per failed request; the client
   * does not attempt to refresh or retry.
   */
  onUnauthenticated?: (error: ApiError) => void;
}

export interface RequestOptions {
  query?: ListQuery | Record<string, unknown>;
  signal?: AbortSignal;
  /** Overrides the client-wide timeout for a slow operation such as an export. */
  timeoutMs?: number;
  headers?: Record<string, string>;
}

/**
 * Typed client for the Naipay API.
 *
 * Speaks the standard envelope: a resolved promise always carries `data` from
 * a successful response, and every failure — including a 200-shaped
 * `success: false` — is thrown as an ApiError. Callers never inspect
 * `success` themselves.
 *
 * Transport-agnostic by design (plain fetch, no framework coupling) so the
 * merchant portal and the React Native app can use it unchanged.
 */
export class NaipayApiClient {
  private readonly baseUrl: string;
  private readonly tokenStore?: TokenStore;
  private readonly timeoutMs: number;
  private readonly onUnauthenticated?: (error: ApiError) => void;

  constructor(options: ApiClientOptions) {
    this.baseUrl = options.baseUrl.replace(/\/+$/, '');
    this.tokenStore = options.tokenStore;
    this.timeoutMs = options.timeoutMs ?? 30_000;
    this.onUnauthenticated = options.onUnauthenticated;
  }

  get<TData>(path: string, options?: RequestOptions): Promise<TData> {
    return this.request<TData>('GET', path, undefined, options);
  }

  post<TData>(path: string, body?: unknown, options?: RequestOptions): Promise<TData> {
    return this.request<TData>('POST', path, body, options);
  }

  put<TData>(path: string, body?: unknown, options?: RequestOptions): Promise<TData> {
    return this.request<TData>('PUT', path, body, options);
  }

  patch<TData>(path: string, body?: unknown, options?: RequestOptions): Promise<TData> {
    return this.request<TData>('PATCH', path, body, options);
  }

  delete<TData>(path: string, options?: RequestOptions): Promise<TData> {
    return this.request<TData>('DELETE', path, undefined, options);
  }

  /**
   * Fetches a list endpoint, returning items alongside pagination rather than
   * making every caller dig through `meta`.
   */
  async list<TItem>(path: string, options?: RequestOptions): Promise<PaginatedResult<TItem>> {
    const envelope = await this.requestEnvelope<TItem[]>('GET', path, undefined, options);

    return {
      items: envelope.data,
      pagination: envelope.meta.pagination ?? {
        current_page: 1,
        per_page: envelope.data.length,
        total: envelope.data.length,
        last_page: 1,
        from: envelope.data.length > 0 ? 1 : null,
        to: envelope.data.length > 0 ? envelope.data.length : null,
        has_more_pages: false,
      },
    };
  }

  /** Downloads a generated receipt, statement or export as a Blob. */
  async download(path: string, options?: RequestOptions): Promise<Blob> {
    const response = await this.send('GET', path, undefined, options, 'application/octet-stream');

    if (!response.ok) {
      throw await this.toApiError(response);
    }

    return response.blob();
  }

  private async request<TData>(
    method: string,
    path: string,
    body: unknown,
    options?: RequestOptions,
  ): Promise<TData> {
    const envelope = await this.requestEnvelope<TData>(method, path, body, options);

    return envelope.data;
  }

  private async requestEnvelope<TData>(
    method: string,
    path: string,
    body: unknown,
    options?: RequestOptions,
  ): Promise<ApiSuccess<TData>> {
    const response = await this.send(method, path, body, options, 'application/json');

    let envelope: ApiEnvelope<TData> | null = null;

    try {
      envelope = (await response.json()) as ApiEnvelope<TData>;
    } catch {
      // Fall through: a non-JSON body from a proxy or gateway is handled below.
    }

    if (!response.ok || envelope === null || envelope.success === false) {
      throw this.buildError(response, envelope);
    }

    return envelope;
  }

  private async send(
    method: string,
    path: string,
    body: unknown,
    options: RequestOptions | undefined,
    accept: string,
  ): Promise<Response> {
    const url = this.buildUrl(path, options?.query);

    const headers: Record<string, string> = {
      Accept: accept === 'application/json' ? 'application/json' : `${accept}, application/json`,
      // Every request carries an ID so a user action can be traced through the
      // API logs, queued jobs and the audit trail.
      [CORRELATION_HEADER]: generateCorrelationId(),
      ...options?.headers,
    };

    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }

    const token = await this.tokenStore?.get();

    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }

    // Combines the caller's abort signal with the timeout, so whichever fires
    // first cancels the request.
    const timeout = new AbortController();
    const timeoutMs = options?.timeoutMs ?? this.timeoutMs;
    const timer = setTimeout(() => timeout.abort(), timeoutMs);

    const signal = options?.signal
      ? anySignal([options.signal, timeout.signal])
      : timeout.signal;

    try {
      return await fetch(url, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
        signal,
        // Bearer tokens are used rather than cookies, so no credentials are
        // sent cross-origin.
        credentials: 'omit',
      });
    } catch (cause) {
      if (options?.signal?.aborted) {
        throw cause;
      }

      if (timeout.signal.aborted) {
        throw new NetworkError(`The request to Naipay timed out after ${timeoutMs}ms.`, cause);
      }

      throw new NetworkError('Could not reach Naipay. Check your connection and try again.', cause);
    } finally {
      clearTimeout(timer);
    }
  }

  private buildUrl(path: string, query?: Record<string, unknown>): string {
    const url = new URL(`${this.baseUrl}/${path.replace(/^\/+/, '')}`);

    for (const [key, value] of Object.entries(query ?? {})) {
      if (value === undefined || value === null || value === '') {
        continue;
      }

      url.searchParams.set(key, String(value));
    }

    return url.toString();
  }

  private buildError(response: Response, envelope: ApiEnvelope<unknown> | null): ApiError {
    const correlationId = response.headers.get(CORRELATION_HEADER);

    const message =
      envelope !== null && 'message' in envelope && typeof envelope.message === 'string'
        ? envelope.message
        : defaultMessageFor(response.status);

    const errors =
      envelope !== null && envelope.success === false ? (envelope.errors ?? {}) : {};

    const error = new ApiError(message, response.status, errors, correlationId);

    if (error.isUnauthenticated) {
      void this.tokenStore?.clear();
      this.onUnauthenticated?.(error);
    }

    return error;
  }

  private async toApiError(response: Response): Promise<ApiError> {
    let envelope: ApiEnvelope<unknown> | null = null;

    try {
      envelope = (await response.json()) as ApiEnvelope<unknown>;
    } catch {
      envelope = null;
    }

    return this.buildError(response, envelope);
  }
}

function defaultMessageFor(status: number): string {
  if (status === 401) return 'Your session has expired. Please sign in again.';
  if (status === 403) return 'You do not have permission to perform this action.';
  if (status === 404) return 'The requested record was not found.';
  if (status === 429) return 'Too many requests. Please wait a moment and try again.';
  if (status >= 500) return 'Naipay encountered an unexpected error. Please try again.';

  return 'The request could not be completed.';
}

/** Available in every supported runtime; falls back for older React Native. */
function generateCorrelationId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `naipay-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

/** AbortSignal.any is not yet available everywhere Naipay runs. */
function anySignal(signals: AbortSignal[]): AbortSignal {
  if (typeof AbortSignal !== 'undefined' && 'any' in AbortSignal) {
    return (AbortSignal as unknown as { any(s: AbortSignal[]): AbortSignal }).any(signals);
  }

  const controller = new AbortController();

  for (const signal of signals) {
    if (signal.aborted) {
      controller.abort(signal.reason);
      break;
    }

    signal.addEventListener('abort', () => controller.abort(signal.reason), { once: true });
  }

  return controller.signal;
}
