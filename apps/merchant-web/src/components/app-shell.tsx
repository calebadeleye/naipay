'use client';

import Image from 'next/image';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import {
  FileText,
  FolderOpen,
  Landmark,
  LayoutDashboard,
  LogOut,
  Menu,
  Receipt,
  User,
  X,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { cn } from '@/lib/cn';
import { merchantLabel } from '@/lib/format';
import { useCurrentMerchant, useLogout } from '@/lib/auth/use-auth';

const NAV_ITEMS = [
  { label: 'Dashboard', href: '/', icon: LayoutDashboard },
  { label: 'Loans', href: '/loans', icon: Landmark },
  { label: 'Apply for a loan', href: '/loan-applications', icon: FileText },
  { label: 'Receipts', href: '/receipts', icon: Receipt },
  { label: 'Documents', href: '/documents', icon: FolderOpen },
  { label: 'Profile', href: '/profile', icon: User },
];

function NavContent({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();

  return (
    <nav className="flex-1 space-y-1 overflow-y-auto px-3 py-6">
      {NAV_ITEMS.map((item) => {
        const active = pathname === item.href || pathname.startsWith(`${item.href}/`);
        const Icon = item.icon;

        return (
          <Link
            key={item.href}
            href={item.href}
            aria-current={active ? 'page' : undefined}
            onClick={onNavigate}
            className={cn(
              'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
              active
                ? 'border-l-2 border-accent-400 bg-white/15 text-white shadow-inner shadow-black/10'
                : 'text-brand-100 hover:bg-white/10 hover:text-white',
            )}
          >
            <Icon className="size-4 shrink-0" aria-hidden />
            {item.label}
          </Link>
        );
      })}
    </nav>
  );
}

/** The frame every authenticated portal screen renders inside. */
export function AppShell({ children }: { children: ReactNode }) {
  const { data: merchant } = useCurrentMerchant();
  const logout = useLogout();
  const [mobileNavOpen, setMobileNavOpen] = useState(false);

  return (
    <div className="flex min-h-screen">
      <aside className="glass-surface-dark fixed inset-y-0 left-0 hidden w-64 flex-col rounded-none lg:flex">
        <div className="flex h-16 items-center gap-2.5 border-b border-white/10 px-6">
          <Image src="/every_logo_mark.png" alt="" width={28} height={28} className="shrink-0" priority />
          <span className="text-lg font-semibold tracking-tight text-white">Every Merchant</span>
        </div>

        <NavContent />
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

            <NavContent onNavigate={() => setMobileNavOpen(false)} />
          </aside>
        </div>
      ) : null}

      <div className="flex min-h-screen min-w-0 flex-1 flex-col lg:pl-64">
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
            {merchant ? (
              <div className="text-right">
                <p className="text-sm font-medium text-slate-900">{merchant.full_name}</p>
                <p className="text-xs text-slate-500">{merchantLabel(merchant)}</p>
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
          <div className="mx-auto max-w-5xl space-y-6">{children}</div>
        </main>
      </div>
    </div>
  );
}
