import Link from 'next/link';
import type { ReactNode } from 'react';

/**
 * Frame shared by every unauthenticated screen.
 *
 * Deliberately plain: these pages are reachable without a token, so they carry
 * no portfolio figures, no merchant data and no navigation into the console.
 */
export function AuthShell({
  title,
  description,
  children,
  footer,
}: {
  title: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-12">
      <div className="w-full max-w-md">
        <div className="mb-8 text-center">
          <Link href="/" className="inline-flex items-baseline gap-2">
            <span className="text-2xl font-semibold tracking-tight text-brand-700">Naipay</span>
          </Link>
          <p className="mt-1 text-sm text-slate-500">Merchant Microfinance</p>
        </div>

        <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <h1 className="text-lg font-semibold text-slate-900">{title}</h1>
          {description ? <p className="mt-1 text-sm text-slate-600">{description}</p> : null}

          <div className="mt-6">{children}</div>
        </div>

        {footer ? <div className="mt-6 text-center text-sm">{footer}</div> : null}

        <p className="mt-8 text-center text-xs text-slate-400">
          Authorised staff only. All activity is recorded.
        </p>
      </div>
    </main>
  );
}
