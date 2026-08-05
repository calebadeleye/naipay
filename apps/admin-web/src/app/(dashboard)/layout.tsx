'use client';

import { Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';

import { AppShell } from '@/components/layout/app-shell';
import { useCurrentStaff } from '@/lib/auth/use-auth';

/**
 * Wraps every authenticated screen. `useCurrentStaff()` also doubles as the
 * auth guard: a 401 here has already been redirected to sign-in by the API
 * client's `onUnauthenticated` handler (see lib/api.ts), so this only needs
 * to hold the screen empty until that hard navigation actually happens.
 */
export default function DashboardLayout({ children }: { children: ReactNode }) {
  const { data: staff, isLoading, isError } = useCurrentStaff();

  if (isLoading || (isError && !staff)) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <Loader2 className="size-6 animate-spin text-slate-400" aria-hidden />
      </div>
    );
  }

  return <AppShell>{children}</AppShell>;
}
