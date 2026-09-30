import { z } from 'zod';

import { readServerRuntimeEnv, type RuntimeEnv } from '@/lib/runtime-env';

/**
 * Validated environment for the administrative console.
 *
 * Parsed once at module load so a missing or malformed variable fails the
 * first render with a clear message, rather than surfacing later as a
 * request to `undefined/api/v1/merchants`.
 *
 * Only NEXT_PUBLIC_* values belong here — everything in this file reaches the
 * browser, so no secret may ever be added to it.
 *
 * Where the values come from (later wins):
 *   1. Values inlined by `next build` (the original behaviour) — kept as a
 *      fallback so a build made with NEXT_PUBLIC_* set keeps working.
 *   2. On the server: the process environment, read at runtime.
 *   3. In the browser: `window.__NAIPAY_RUNTIME_ENV__`, written by
 *      /runtime-config.js from the *server's* environment at request time.
 *   Because of (2) and (3) one build can be copied to any number of
 *   deployments, each configured only by its own .env at start.
 */
const schema = z.object({
  NEXT_PUBLIC_API_URL: z
    .string()
    .url('NEXT_PUBLIC_API_URL must be a full URL, including the /api/v1 prefix.'),
  NEXT_PUBLIC_APP_NAME: z.string().default('Every Merchant'),
  NEXT_PUBLIC_ENVIRONMENT: z
    .enum(['local', 'staging', 'production'])
    .default('local'),
});

// Explicit literals on purpose: this is the *build-time* fallback, and
// Next.js only inlines NEXT_PUBLIC_* where it can see them statically.
const inlinedAtBuild: RuntimeEnv = {
  NEXT_PUBLIC_API_URL: process.env.NEXT_PUBLIC_API_URL,
  NEXT_PUBLIC_APP_NAME: process.env.NEXT_PUBLIC_APP_NAME,
  NEXT_PUBLIC_ENVIRONMENT: process.env.NEXT_PUBLIC_ENVIRONMENT,
};

function runtimeValues(): RuntimeEnv {
  const source =
    typeof window === 'undefined'
      ? readServerRuntimeEnv()
      : (window.__NAIPAY_RUNTIME_ENV__ ?? {});

  return { ...inlinedAtBuild, ...source };
}

// `next build` evaluates these modules while collecting page data, with no
// deployment environment at all. That is expected now (the real values are
// supplied at start), so the build gets a placeholder instead of a failure;
// nothing at build time reaches a user.
const isBuilding = process.env.NEXT_PHASE === 'phase-production-build';

const parsed = schema.safeParse(
  isBuilding
    ? { NEXT_PUBLIC_API_URL: 'http://localhost:8000/api/v1', ...runtimeValues() }
    : runtimeValues(),
);

if (!parsed.success) {
  const issues = parsed.error.issues
    .map((issue) => `  - ${issue.path.join('.')}: ${issue.message}`)
    .join('\n');

  throw new Error(
    `Every Merchant admin console environment is not configured correctly:\n${issues}\n\n` +
      'Set NEXT_PUBLIC_API_URL (and optionally NEXT_PUBLIC_APP_NAME, NEXT_PUBLIC_ENVIRONMENT) ' +
      'in the environment or .env.production.local the app is started with — ' +
      'or copy .env.example to .env.local for development.',
  );
}

export const env = parsed.data;

export const isProduction = env.NEXT_PUBLIC_ENVIRONMENT === 'production';
