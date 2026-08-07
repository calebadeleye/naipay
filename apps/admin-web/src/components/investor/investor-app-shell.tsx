'use client';

import Image from 'next/image';
import Link from 'next/link';
import { LayoutDashboard, LogOut, Menu, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { useCurrentInvestor, useInvestorLogout } from '@/lib/investor-auth/use-investor-auth';

function InvestorNavContent({ onNavigate }: { onNavigate?: () => void }) {
  return (
    <nav className="flex-1 space-y-6 overflow-y-auto px-3 py-6">
      <div>
        <p className="px-3 text-xs font-semibold tracking-wide text-brand-300 uppercase">Overview</p>

        <ul className="mt-2 space-y-0.5">
          <li>
            <Link
              href="/investor/dashboard"
              aria-current="page"
              onClick={onNavigate}
              className="flex items-center gap-2.5 rounded-lg border-l-2 border-accent-400 bg-white/15 px-3 py-2 text-sm font-medium text-white shadow-inner shadow-black/10 transition-colors"
            >
              <LayoutDashboard className="size-4 shrink-0" aria-hidden />
              Dashboard
            </Link>
          </li>
        </ul>
      </div>
    </nav>
  );
}

/**
 * The frame every investor screen renders inside — a single-item sidebar,
 * since an investor has exactly one thing to look at. Mirrors AppShell's
 * layout (including the mobile drawer) so the two portals read as the same
 * product, but there is no navigation config here: there is nothing to
 * navigate to.
 */
export function InvestorAppShell({ children }: { children: ReactNode }) {
  const { data: investor } = useCurrentInvestor();
  const logout = useInvestorLogout();
  const [mobileNavOpen, setMobileNavOpen] = useState(false);

  return (
    <div className="flex min-h-screen">
      <aside className="glass-surface-dark fixed inset-y-0 left-0 hidden w-64 flex-col rounded-none lg:flex">
        <div className="flex h-16 items-center gap-2.5 border-b border-white/10 px-6">
          <Image src="/every_logo_mark.png" alt="" width={28} height={28} className="shrink-0" priority />
          <span className="text-lg font-semibold tracking-tight text-white">Every Merchant</span>
        </div>

        <InvestorNavContent />
      </aside>

      {mobileNavOpen ? (
        <div className="fixed inset-0 z-40 lg:hidden">
          <button
            type="button"
            aria-label="Close navigation"
            className="absolute inset-0 bg-slate-950/60"
            onClick={() => setMobileNavOpen(false)}
          />

          <aside className="glass-surface-dark relative flex h-full w-64 max-w-[80vw] flex-col rounded-none shadow-xl">
            <div className="flex h-16 items-center justify-between gap-2.5 border-b border-white/10 px-6">
              <div className="flex items-center gap-2.5">
                <Image src="/every_logo_mark.png" alt="" width={28} height={28} className="shrink-0" priority />
                <span className="text-lg font-semibold tracking-tight text-white">Every Merchant</span>
              </div>

              <button
                type="button"
                aria-label="Close navigation"
                onClick={() => setMobileNavOpen(false)}
                className="rounded-md p-1.5 text-brand-100 hover:bg-white/10 hover:text-white"
              >
                <X className="size-5" aria-hidden />
              </button>
            </div>

            <InvestorNavContent onNavigate={() => setMobileNavOpen(false)} />
          </aside>
        </div>
      ) : null}

      <div className="flex min-h-screen flex-1 flex-col lg:pl-64">
        <header className="glass-surface sticky top-0 z-10 flex h-16 items-center rounded-none border-x-0 border-t-0 px-6">
          <button
            type="button"
            aria-label="Open navigation"
            onClick={() => setMobileNavOpen(true)}
            className="rounded-md p-1.5 text-slate-600 hover:bg-slate-100 hover:text-slate-900 lg:hidden"
          >
            <Menu className="size-5" aria-hidden />
          </button>

          <div className="ml-auto flex items-center gap-4">
            {investor ? (
              <div className="text-right">
                <p className="text-sm font-medium text-slate-900">{investor.name}</p>
                <p className="text-xs text-slate-500">Investor</p>
              </div>
            ) : null}

            <button
              type="button"
              onClick={() => logout.mutate()}
              disabled={logout.isPending}
              className="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-60"
            >
              <LogOut className="size-4" aria-hidden />
              Sign out
            </button>
          </div>
        </header>

        <main className="glass-scrim flex-1 px-6 py-8">
          <div className="mx-auto max-w-6xl space-y-6">{children}</div>
        </main>
      </div>
    </div>
  );
}
