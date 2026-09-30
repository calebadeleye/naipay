/**
 * The runtime-configurable values of the administrative console.
 *
 * `next build` freezes every `NEXT_PUBLIC_*` value into the bundle, so one
 * build could only ever serve one API URL. These are instead read from the
 * server's own environment each time `/runtime-config.js` is requested (see
 * src/app/runtime-config.js/route.ts), which lets a single prebuilt artifact
 * be copied and started against any number of different deployments.
 *
 * Only values that are safe to ship to every visitor belong here — this list
 * is served to the browser verbatim.
 */
export const RUNTIME_ENV_KEYS = [
  'NEXT_PUBLIC_API_URL',
  'NEXT_PUBLIC_APP_NAME',
  'NEXT_PUBLIC_ENVIRONMENT',
] as const;

export type RuntimeEnvKey = (typeof RUNTIME_ENV_KEYS)[number];

export type RuntimeEnv = Partial<Record<RuntimeEnvKey, string>>;

/**
 * Reads the listed keys from the server's process environment.
 *
 * The lookup is deliberately `process.env[key]` with a variable, never
 * `process.env.NEXT_PUBLIC_API_URL`: Next.js inlines the literal form at
 * build time (even in server code), which would freeze the value again.
 * Dynamic lookups are not inlined — see "Runtime Environment Variables" in
 * Next's environment-variables guide.
 */
export function readServerRuntimeEnv(): RuntimeEnv {
  const values: RuntimeEnv = {};

  for (const key of RUNTIME_ENV_KEYS) {
    const value = process.env[key];
    if (value !== undefined && value !== '') {
      values[key] = value;
    }
  }

  return values;
}

declare global {
  interface Window {
    /** Set by /runtime-config.js, which the root layout loads before any bundle. */
    __NAIPAY_RUNTIME_ENV__?: RuntimeEnv;
  }
}
