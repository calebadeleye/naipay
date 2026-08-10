'use client';

import { Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';

import { AppShell } from '@/components/app-shell';
import { useCurrentMerchant } from '@/lib/auth/use-auth';

export default function PortalLayout({ children }: { children: ReactNode }) {
  const { data: merchant, isLoading, isError } = useCurrentMerchant();

  if (isLoading || (isError && !merchant)) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <Loader2 className="size-6 animate-spin text-slate-400" aria-hidden />
      </div>
    );
  }

  return <AppShell>{children}</AppShell>;
}
