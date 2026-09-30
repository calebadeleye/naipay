import type { Metadata } from 'next';
import { Inter, JetBrains_Mono } from 'next/font/google';

import { QueryProvider } from '@/lib/query-client';

import './globals.css';

const inter = Inter({
  variable: '--font-inter',
  subsets: ['latin'],
  display: 'swap',
});

/** Tabular figures for amounts, references and account numbers. */
const jetbrainsMono = JetBrains_Mono({
  variable: '--font-jetbrains-mono',
  subsets: ['latin'],
  display: 'swap',
});

export const metadata: Metadata = {
  title: {
    default: 'Every Merchant Administration',
    template: '%s · Every Merchant',
  },
  description: 'Every Merchant microfinance administration platform.',
  // An internal financial console must never be indexed, and referrers must
  // not leak record identifiers to third parties.
  robots: {
    index: false,
    follow: false,
    nocache: true,
  },
  referrer: 'no-referrer',
};

// Render per request, never at build time: the API URL and app name come
// from the environment the server was *started* with (see lib/env.ts), so
// nothing may be prerendered with whatever the build machine happened to have.
export const dynamic = 'force-dynamic';

export default function RootLayout({ children }: LayoutProps<'/'>) {
  return (
    <html
      lang="en"
      className={`${inter.variable} ${jetbrainsMono.variable} h-full antialiased`}
    >
      <head>
        {/* Deliberately a plain, synchronous script: it must have set
            window.__NAIPAY_RUNTIME_ENV__ before any bundle runs, because
            lib/env.ts reads it at module load. */}
        {/* eslint-disable-next-line @next/next/no-sync-scripts */}
        <script src="/runtime-config.js" />
      </head>
      <body className="min-h-full font-sans">
        <QueryProvider>{children}</QueryProvider>
      </body>
    </html>
  );
}
