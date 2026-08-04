import { z } from 'zod';

/**
 * Validated environment for the administrative console.
 *
 * Parsed once at module load so a missing or malformed variable fails the
 * build or the first render with a clear message, rather than surfacing later
 * as a request to `undefined/api/v1/merchants`.
 *
 * Only NEXT_PUBLIC_* values belong here — everything in this file is compiled
 * into the browser bundle, so no secret may ever be added to it.
 */
const schema = z.object({
  NEXT_PUBLIC_API_URL: z
    .string()
    .url('NEXT_PUBLIC_API_URL must be a full URL, including the /api/v1 prefix.'),
  NEXT_PUBLIC_APP_NAME: z.string().default('Naipay'),
  NEXT_PUBLIC_ENVIRONMENT: z
    .enum(['local', 'staging', 'production'])
    .default('local'),
});

// Referenced as explicit literals rather than by index: Next.js inlines
// NEXT_PUBLIC_* values at build time only where it can see them statically.
const parsed = schema.safeParse({
  NEXT_PUBLIC_API_URL: process.env.NEXT_PUBLIC_API_URL,
  NEXT_PUBLIC_APP_NAME: process.env.NEXT_PUBLIC_APP_NAME,
  NEXT_PUBLIC_ENVIRONMENT: process.env.NEXT_PUBLIC_ENVIRONMENT,
});

if (!parsed.success) {
  const issues = parsed.error.issues
    .map((issue) => `  - ${issue.path.join('.')}: ${issue.message}`)
    .join('\n');

  throw new Error(
    `Naipay admin console environment is not configured correctly:\n${issues}\n\n` +
      'Copy .env.example to .env.local and fill in the values.',
  );
}

export const env = parsed.data;

export const isProduction = env.NEXT_PUBLIC_ENVIRONMENT === 'production';
