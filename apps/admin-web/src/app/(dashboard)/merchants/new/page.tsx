'use client';

import { ApiError } from '@naipay/api-client';
import { useRouter } from 'next/navigation';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select';
import { useCreateMerchant } from '@/lib/merchants/use-merchants';
import type { MerchantFormInput } from '@/lib/merchants/types';

const genderOptions = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' },
  { value: 'prefer_not_to_say', label: 'Prefer not to say' },
];

const emptyForm: MerchantFormInput = {
  first_name: '',
  middle_name: '',
  last_name: '',
  date_of_birth: '',
  gender: '',
  phone: '',
  alternative_phone: '',
  email: '',
  residential_address: '',
  city: '',
  state: '',
  country: 'Nigeria',
  bvn: '',
  nin: '',
};

export default function NewMerchantPage() {
  const router = useRouter();
  const [form, setForm] = useState<MerchantFormInput>(emptyForm);
  const createMerchant = useCreateMerchant();
  const error = createMerchant.error instanceof ApiError ? createMerchant.error : null;

  function set<K extends keyof MerchantFormInput>(key: K, value: MerchantFormInput[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();

    try {
      const merchant = await createMerchant.mutateAsync(form);
      router.replace(`/merchants/${merchant.id}`);
    } catch {
      // Surfaced via `error` above, rendered from the mutation state.
    }
  }

  return (
    <>
      <PageHeader
        title="New merchant"
        description="Starts the merchant in Draft. Identity and KYC details can be completed before it is submitted for verification."
      />

      <Card className="max-w-3xl">
        <form onSubmit={handleSubmit} className="space-y-5">
          {error && !error.isValidation ? (
            <Alert tone="error" reference={error.correlationId}>
              {error.message}
            </Alert>
          ) : null}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Field
              label="First name"
              required
              value={form.first_name}
              onChange={(event) => set('first_name', event.target.value)}
              error={error?.fieldError('first_name')}
            />
            <Field
              label="Middle name"
              value={form.middle_name}
              onChange={(event) => set('middle_name', event.target.value)}
              error={error?.fieldError('middle_name')}
            />
            <Field
              label="Last name"
              required
              value={form.last_name}
              onChange={(event) => set('last_name', event.target.value)}
              error={error?.fieldError('last_name')}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="Date of birth"
              type="date"
              value={form.date_of_birth}
              onChange={(event) => set('date_of_birth', event.target.value)}
              error={error?.fieldError('date_of_birth')}
            />
            <SelectField
              label="Gender"
              placeholder="Select a gender"
              options={genderOptions}
              value={form.gender}
              onChange={(event) => set('gender', event.target.value)}
              error={error?.fieldError('gender')}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="Phone"
              required
              placeholder="080..."
              value={form.phone}
              onChange={(event) => set('phone', event.target.value)}
              error={error?.fieldError('phone')}
            />
            <Field
              label="Alternative phone"
              value={form.alternative_phone}
              onChange={(event) => set('alternative_phone', event.target.value)}
              error={error?.fieldError('alternative_phone')}
            />
          </div>

          <Field
            label="Email"
            type="email"
            value={form.email}
            onChange={(event) => set('email', event.target.value)}
            error={error?.fieldError('email')}
          />

          <Field
            label="Residential address"
            value={form.residential_address}
            onChange={(event) => set('residential_address', event.target.value)}
            error={error?.fieldError('residential_address')}
          />

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Field
              label="City"
              value={form.city}
              onChange={(event) => set('city', event.target.value)}
              error={error?.fieldError('city')}
            />
            <Field
              label="State"
              value={form.state}
              onChange={(event) => set('state', event.target.value)}
              error={error?.fieldError('state')}
            />
            <Field
              label="Country"
              value={form.country}
              onChange={(event) => set('country', event.target.value)}
              error={error?.fieldError('country')}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="BVN"
              placeholder="11 digits"
              value={form.bvn}
              onChange={(event) => set('bvn', event.target.value)}
              error={error?.fieldError('bvn')}
              hint="Stored encrypted; only shown masked outside sensitive-data roles."
            />
            <Field
              label="NIN"
              placeholder="11 digits"
              value={form.nin}
              onChange={(event) => set('nin', event.target.value)}
              error={error?.fieldError('nin')}
            />
          </div>

          <div className="flex gap-3">
            <Button type="submit" loading={createMerchant.isPending}>
              Create merchant
            </Button>
          </div>
        </form>
      </Card>
    </>
  );
}
