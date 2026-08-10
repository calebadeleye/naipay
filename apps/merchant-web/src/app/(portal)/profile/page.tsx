'use client';

import { ApiError } from '@naipay/api-client';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { useCurrentMerchant } from '@/lib/auth/use-auth';
import type { Business, Merchant } from '@/lib/auth/types';
import { useUpdateBusiness, useUpdateProfile, type MerchantSelfFormInput } from '@/lib/profile/use-profile';

function toFormInput(merchant: Merchant): MerchantSelfFormInput {
  return {
    first_name: merchant.first_name,
    middle_name: merchant.middle_name ?? '',
    last_name: merchant.last_name,
    phone: merchant.phone ?? '',
    alternative_phone: merchant.alternative_phone ?? '',
    email: merchant.email ?? '',
    residential_address: merchant.residential_address ?? '',
    city: merchant.city ?? '',
    state: merchant.state ?? '',
    country: merchant.country ?? '',
  };
}

function BusinessCard({ business }: { business: Business }) {
  const [editing, setEditing] = useState(false);
  const [name, setName] = useState(business.business_name);
  const updateBusiness = useUpdateBusiness(business.id);
  const error = updateBusiness.error as ApiError | null;

  async function handleSave() {
    const updated = await updateBusiness.mutateAsync({ business_name: name }).catch(() => null);

    if (updated) {
      setEditing(false);
    }
  }

  return (
    <Card>
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}

      {editing ? (
        <div className="space-y-3">
          <Field
            label="Business name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            error={error?.fieldError('business_name')}
          />
          <div className="flex gap-2">
            <Button type="button" size="sm" loading={updateBusiness.isPending} onClick={handleSave}>
              Save
            </Button>
            <Button type="button" size="sm" variant="ghost" onClick={() => setEditing(false)}>
              Cancel
            </Button>
          </div>
        </div>
      ) : (
        <div className="flex items-center justify-between">
          <div>
            <p className="text-sm font-medium text-slate-900">{business.business_name}</p>
            <p className="text-xs text-slate-500">
              {business.business_number} · {business.business_type_label}
            </p>
          </div>
          <Button type="button" size="sm" variant="secondary" onClick={() => setEditing(true)}>
            Edit
          </Button>
        </div>
      )}
    </Card>
  );
}

export default function ProfilePage() {
  const { data: merchant, isLoading, error } = useCurrentMerchant();
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<MerchantSelfFormInput | null>(null);

  const updateProfile = useUpdateProfile();
  const updateError = updateProfile.error as ApiError | null;

  function startEditing(current: Merchant) {
    setForm(toFormInput(current));
    setEditing(true);
  }

  function set<K extends keyof MerchantSelfFormInput>(key: K, value: MerchantSelfFormInput[K]) {
    if (!form) return;
    setForm({ ...form, [key]: value });
  }

  async function handleSave(event: React.FormEvent) {
    event.preventDefault();
    if (!form) return;

    const updated = await updateProfile.mutateAsync(form).catch(() => null);

    if (updated) {
      setEditing(false);
    }
  }

  return (
    <QueryState isLoading={isLoading} error={error}>
      {merchant ? (
        <div className="space-y-6">
          <PageHeader
            title="Your profile"
            description={`${merchant.merchant_number} · ${merchant.email ?? 'No email on file'}`}
            actions={
              !editing ? (
                <Button type="button" variant="secondary" onClick={() => startEditing(merchant)}>
                  Edit
                </Button>
              ) : undefined
            }
          />

          {editing && form ? (
            <Card className="max-w-2xl">
              <form onSubmit={handleSave} className="space-y-5">
                {updateError && !updateError.isValidation ? (
                  <Alert tone="error" reference={updateError.correlationId}>
                    {updateError.message}
                  </Alert>
                ) : null}

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <Field
                    label="First name"
                    value={form.first_name}
                    onChange={(event) => set('first_name', event.target.value)}
                    error={updateError?.fieldError('first_name')}
                  />
                  <Field
                    label="Middle name"
                    value={form.middle_name}
                    onChange={(event) => set('middle_name', event.target.value)}
                  />
                  <Field
                    label="Last name"
                    value={form.last_name}
                    onChange={(event) => set('last_name', event.target.value)}
                    error={updateError?.fieldError('last_name')}
                  />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field
                    label="Phone"
                    value={form.phone}
                    onChange={(event) => set('phone', event.target.value)}
                    error={updateError?.fieldError('phone')}
                  />
                  <Field
                    label="Email"
                    type="email"
                    value={form.email}
                    onChange={(event) => set('email', event.target.value)}
                    error={updateError?.fieldError('email')}
                  />
                </div>

                <Field
                  label="Residential address"
                  value={form.residential_address}
                  onChange={(event) => set('residential_address', event.target.value)}
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="City" value={form.city} onChange={(event) => set('city', event.target.value)} />
                  <Field label="State" value={form.state} onChange={(event) => set('state', event.target.value)} />
                </div>

                <div className="flex gap-3">
                  <Button type="submit" loading={updateProfile.isPending}>
                    Save changes
                  </Button>
                  <Button type="button" variant="ghost" onClick={() => setEditing(false)}>
                    Cancel
                  </Button>
                </div>
              </form>
            </Card>
          ) : (
            <Card className="max-w-2xl">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">Phone</p>
                  <p className="mt-0.5 text-sm text-slate-900">{merchant.phone ?? '—'}</p>
                </div>
                <div>
                  <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">Email</p>
                  <p className="mt-0.5 text-sm text-slate-900">{merchant.email ?? '—'}</p>
                </div>
                <div>
                  <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">Address</p>
                  <p className="mt-0.5 text-sm text-slate-900">{merchant.residential_address ?? '—'}</p>
                </div>
                <div>
                  <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">KYC status</p>
                  <p className="mt-0.5 text-sm text-slate-900">{merchant.kyc_status_label}</p>
                </div>
              </div>
            </Card>
          )}

          <div>
            <h2 className="mb-3 text-sm font-semibold text-slate-900">Your businesses</h2>
            <div className="space-y-3">
              {merchant.businesses.length === 0 ? (
                <Card>
                  <p className="text-sm text-slate-500">No businesses on file yet.</p>
                </Card>
              ) : (
                merchant.businesses.map((business) => <BusinessCard key={business.id} business={business} />)
              )}
            </div>
          </div>
        </div>
      ) : null}
    </QueryState>
  );
}
