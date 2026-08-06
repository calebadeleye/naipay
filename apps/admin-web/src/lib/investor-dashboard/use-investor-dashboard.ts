'use client';

import { useQuery } from '@tanstack/react-query';

import { investorApi } from '@/lib/investor-auth/api';
import type { InvestorDashboard } from '@/lib/investor-dashboard/types';

export function useInvestorDashboard() {
  return useQuery({
    queryKey: ['investor-dashboard'],
    queryFn: ({ signal }) => investorApi.get<InvestorDashboard>('/investor/dashboard', { signal }),
  });
}
