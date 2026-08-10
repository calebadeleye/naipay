'use client';

import { useQuery } from '@tanstack/react-query';

import { api } from '@/lib/auth/api';
import type { RepaymentSummary } from '@/lib/repayment-summary/types';

export function useRepaymentSummary() {
  return useQuery({
    queryKey: ['repayment-summary'],
    queryFn: ({ signal }) => api.get<RepaymentSummary>('/merchant/repayments/summary', { signal }),
  });
}
