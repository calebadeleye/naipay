'use client';

import { Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';

import { InvestorAppShell } from '@/components/investor/investor-app-shell';
import { useCurrentInvestor } from '@/lib/investor-auth/use-investor-auth';

export default function InvestorProtectedLayout({ children }: { children: ReactNode }) {
  const { data: investor, isLoading, isError } = useCurrentInvestor();

  if (isLoading || (isError && !investor)) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <Loader2 className="size-6 animate-spin text-slate-400" aria-hidden />
      </div>
    );
  }

  return <InvestorAppShell>{children}</InvestorAppShell>;
}
