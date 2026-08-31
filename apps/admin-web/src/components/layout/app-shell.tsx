'use client';

import Image from 'next/image';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { LogOut, Menu, ShieldAlert, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { visibleNavSections } from '@/components/layout/nav-config';
import type { NavSection } from '@/components/layout/nav-config';
import { NotificationBell } from '@/components/layout/notification-bell';
import { useCurrentStaff, useLogout } from '@/lib/auth/use-auth';
import { cn } from '@/lib/cn';

/**
 * Two-factor is mandatory for privileged roles but no longer blocks sign-in —
 * this strip nags, conspicuously and on every screen, until it is set up.
 */
function TwoFactorSetupBanner() {
  return (
    <div className="sticky top-16 z-10 border-b border-amber-300 bg-amber-50 px-6 py-3 text-amber-900">
      <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-1 text-sm">
        <ShieldAlert className="size-5 shrink-0 text-amber-600" aria-hidden />
        <span className="font-semibold">Two-factor authentication is not set up.</span>
        <span className="text-amber-800">
          Your role requires it. Your account still works, but please secure it now.
        </span>
        <Link
          href="/two-factor/enrol"
          className="ml-auto rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700"
        >
          Set up two-factor
        </Link>
      </div>
    </div>
  );
}

function NavContent({
  navSections,
  pathname,
  onNavigate,
}: {
  navSections: NavSection[];
  pathname: string;
  onNavigate?: () => void;
}) {
  return (
    <nav className="flex-1 space-y-6 overflow-y-auto px-3 py-6">
      {navSections.map((section) => (
        <div key={section.label}>
          <p className="px-3 text-xs font-semibold tracking-wide text-brand-300 uppercase">
            {section.label}
          </p>

          <ul className="mt-2 space-y-0.5">
            {section.items.map((item) => {
              const active = pathname === item.href;
              const Icon = item.icon;

              return (
                <li key={item.href}>
                  <Link
                    href={item.href}
                    aria-current={active ? 'page' : undefined}
                    onClick={onNavigate}
                    className={cn(
                      'flex items-center gap-2.5 rounded-lg border-l-2 px-3 py-2 text-sm font-medium transition-colors',
                      active
                        ? 'border-accent-400 bg-white/15 text-white shadow-inner shadow-black/10'
                        : 'border-transparent text-brand-100 hover:bg-white/10 hover:text-white',
                    )}
                  >
                    <Icon className="size-4 shrink-0" aria-hidden />
                    {item.label}
                  </Link>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}

/**
 * The frame every authenticated screen renders inside: a fixed sidebar for
 * navigation and a topbar identifying who is signed in. Below the `lg`
 * breakpoint the sidebar collapses into a hamburger-triggered drawer, since
 * there is no other way to reach navigation on a phone-width screen.
 */
export function AppShell({ children }: { children: ReactNode }) {
  const { data: staff } = useCurrentStaff();
  const logout = useLogout();
  const pathname = usePathname();
  const [mobileNavOpen, setMobileNavOpen] = useState(false);

  // Empty until `staff` loads, rather than briefly showing every section:
  // a screen a staff member can't reach shouldn't flash into view even for
  // one render.
  const navSections = visibleNavSections(staff?.permissions ?? []);

  // Closes the drawer the moment the route changes — including navigation
  // that didn't go through a nav link's own onClick, like the browser back
  // button. Adjusting state during render (rather than in an effect) avoids
  // a redundant extra render on every route change.
  const [renderedPathname, setRenderedPathname] = useState(pathname);
  if (pathname !== renderedPathname) {
    setRenderedPathname(pathname);
    setMobileNavOpen(false);
  }

  return (
    <div className="flex min-h-screen">
      <aside className="glass-surface-dark fixed inset-y-0 left-0 hidden w-64 flex-col rounded-none lg:flex">
        <div className="flex h-16 items-center gap-2.5 border-b border-white/10 px-6">
          <Link href="/" className="flex items-center gap-2.5">
            <Image src="/every_logo_mark.png" alt="" width={28} height={28} className="shrink-0" priority />
            <span className="text-lg font-semibold tracking-tight text-white">Every Merchant</span>
          </Link>
        </div>

        <NavContent navSections={navSections} pathname={pathname} />
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
              <Link href="/" className="flex items-center gap-2.5" onClick={() => setMobileNavOpen(false)}>
                <Image src="/every_logo_mark.png" alt="" width={28} height={28} className="shrink-0" priority />
                <span className="text-lg font-semibold tracking-tight text-white">Every Merchant</span>
              </Link>

              <button
                type="button"
                aria-label="Close navigation"
                onClick={() => setMobileNavOpen(false)}
                className="rounded-md p-1.5 text-brand-100 hover:bg-white/10 hover:text-white"
              >
                <X className="size-5" aria-hidden />
              </button>
            </div>

            <NavContent navSections={navSections} pathname={pathname} onNavigate={() => setMobileNavOpen(false)} />
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
            <NotificationBell />

            {staff ? (
              <Link href="/account" className="text-right hover:opacity-80">
                <p className="text-sm font-medium text-slate-900">{staff.full_name}</p>
                <p className="text-xs text-slate-500">{staff.job_title ?? staff.roles[0]}</p>
              </Link>
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

        {staff?.two_factor.setup_pending ? <TwoFactorSetupBanner /> : null}

        <main className="glass-scrim flex-1 px-6 py-8">
          <div className="mx-auto max-w-6xl space-y-6">{children}</div>
        </main>
      </div>
    </div>
  );
}
