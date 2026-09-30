import { readServerRuntimeEnv } from '@/lib/runtime-env';

// Must run on every request: the whole point is to hand the browser the
// server's *current* environment rather than whatever was present at build.
export const dynamic = 'force-dynamic';

export function GET() {
  // JSON.stringify output is a valid JS expression; escaping `<` keeps a
  // value containing "</script" from ever being misread by an HTML parser.
  const json = JSON.stringify(readServerRuntimeEnv()).replace(/</g, '\\u003c');

  return new Response(`window.__NAIPAY_RUNTIME_ENV__=${json};`, {
    headers: {
      'Content-Type': 'application/javascript; charset=utf-8',
      'Cache-Control': 'no-store',
    },
  });
}
