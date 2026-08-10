'use client';

import type { MoneyValue } from '@naipay/shared-types';
import { useQuery } from '@tanstack/react-query';

import { api } from '@/lib/auth/api';

export interface LoanProductOption {
  id: number;
  name: string;
  description: string | null;
  minimum_amount: MoneyValue;
  maximum_amount: MoneyValue;
  minimum_tenor: number;
  maximum_tenor: number;
  tenor_unit: string;
  tenor_unit_label: string;
  interest_rate: string;
  requires_guarantor: boolean;
  minimum_guarantors: number;
  summary: string;
}

export function useLoanProducts() {
  return useQuery({
    queryKey: ['loan-products'],
    queryFn: ({ signal }) => api.get<LoanProductOption[]>('/merchant/loan-products', { signal }),
  });
}
