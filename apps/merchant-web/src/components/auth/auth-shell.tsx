import Image from 'next/image';
import Link from 'next/link';
import type { ReactNode } from 'react';

/**
 * Frame shared by every unauthenticated screen. Deliberately plain: these
 * pages are reachable without a token, so they carry no loan or account
 * figures.
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
    <main className="glass-backdrop-dark flex min-h-screen items-center justify-center px-4 py-12">
      <div className="w-full max-w-md">
        <div className="mb-8 text-center">
          <Link href="/sign-in" className="inline-flex items-center gap-2.5">
            <Image src="/every_logo_mark.png" alt="" width={36} height={36} priority />
            <span className="text-2xl font-semibold tracking-tight text-white">Every Merchant</span>
          </Link>
          <p className="mt-1 text-sm text-brand-200">Merchant Portal</p>
        </div>

        <div className="glass-surface rounded-2xl p-6">
          <h1 className="text-lg font-semibold text-slate-900">{title}</h1>
          {description ? <p className="mt-1 text-sm text-slate-600">{description}</p> : null}

          <div className="mt-6">{children}</div>
        </div>

        {footer ? <div className="mt-6 text-center text-sm">{footer}</div> : null}

        <p className="mt-8 text-center text-xs text-brand-300">Every Merchant. All activity is recorded.</p>
      </div>
    </main>
  );
}
