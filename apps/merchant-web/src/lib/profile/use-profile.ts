'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/auth/api';
import { authKeys } from '@/lib/auth/use-auth';
import type { Business, Merchant } from '@/lib/auth/types';

export interface MerchantSelfFormInput {
  first_name?: string;
  middle_name?: string;
  last_name?: string;
  phone?: string;
  alternative_phone?: string;
  email?: string;
  residential_address?: string;
  city?: string;
  state?: string;
  country?: string;
}

export function useUpdateProfile() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: MerchantSelfFormInput) => api.patch<Merchant>('/merchant/profile', input),
    onSuccess: (merchant) => {
      queryClient.setQueryData(authKeys.me, merchant);
    },
  });
}

export interface BusinessSelfFormInput {
  business_name?: string;
  business_description?: string;
  business_phone?: string;
  business_email?: string;
  business_address?: string;
  city?: string;
  state?: string;
  country?: string;
}

export function useUpdateBusiness(id: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: BusinessSelfFormInput) => api.patch<Business>(`/merchant/businesses/${id}`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: authKeys.me });
    },
  });
}
