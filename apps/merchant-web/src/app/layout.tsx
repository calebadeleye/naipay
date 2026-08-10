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
    default: 'Every Merchant Portal',
    template: '%s · Every Merchant',
  },
  description: 'View your loans, apply for credit, and manage your Every Merchant account.',
  // An authenticated financial portal must never be indexed, and referrers
  // must not leak record identifiers to third parties.
  robots: {
    index: false,
    follow: false,
    nocache: true,
  },
  referrer: 'no-referrer',
};

export default function RootLayout({ children }: LayoutProps<'/'>) {
  return (
    <html
      lang="en"
      className={`${inter.variable} ${jetbrainsMono.variable} h-full antialiased`}
    >
      <body className="min-h-full font-sans">
        <QueryProvider>{children}</QueryProvider>
      </body>
    </html>
  );
}
