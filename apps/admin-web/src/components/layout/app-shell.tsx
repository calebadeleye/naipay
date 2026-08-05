'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { LogOut } from 'lucide-react';
import type { ReactNode } from 'react';

import { navSections } from '@/components/layout/nav-config';
import { useCurrentStaff, useLogout } from '@/lib/auth/use-auth';
import { cn } from '@/lib/cn';

/**
 * The frame every authenticated screen renders inside: a fixed sidebar for
 * navigation and a topbar identifying who is signed in.
 */
export function AppShell({ children }: { children: ReactNode }) {
  const { data: staff } = useCurrentStaff();
  const logout = useLogout();
  const pathname = usePathname();

  return (
    <div className="flex min-h-screen bg-slate-50">
      <aside className="fixed inset-y-0 left-0 hidden w-64 flex-col border-r border-slate-200 bg-white lg:flex">
        <div className="flex h-16 items-center border-b border-slate-200 px-6">
          <Link href="/" className="text-lg font-semibold tracking-tight text-brand-700">
            Naipay
          </Link>
        </div>

        <nav className="flex-1 space-y-6 overflow-y-auto px-3 py-6">
          {navSections.map((section) => (
            <div key={section.label}>
              <p className="px-3 text-xs font-semibold tracking-wide text-slate-400 uppercase">
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
                        className={cn(
                          'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                          active
                            ? 'bg-brand-50 text-brand-700'
                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
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
      </aside>

      <div className="flex min-h-screen flex-1 flex-col lg:pl-64">
        <header className="sticky top-0 z-10 flex h-16 items-center justify-between border-b border-slate-200 bg-white px-6">
          <div />

          <div className="flex items-center gap-4">
            {staff ? (
              <div className="text-right">
                <p className="text-sm font-medium text-slate-900">{staff.full_name}</p>
                <p className="text-xs text-slate-500">{staff.job_title ?? staff.roles[0]}</p>
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

        <main className="flex-1 px-6 py-8">
          <div className="mx-auto max-w-6xl space-y-6">{children}</div>
        </main>
      </div>
    </div>
  );
}
