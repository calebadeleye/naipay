'use client';

import { ApiError } from '@naipay/api-client';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useMemo, useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select';
import { QueryState } from '@/components/ui/query-state';
import { ActionButton, ReasonActionButton } from '@/components/ui/workflow-action';
import { useHasPermission } from '@/lib/auth/use-permission';
import { formatDateTime, formatMoney } from '@/lib/format';
import {
  useBusiness,
  useBusinessTypeOptions,
  useCategoryOptions,
  useChangeBusinessStatus,
  useRejectBusinessVerification,
  useUpdateBusiness,
  useVerifyBusiness,
} from '@/lib/businesses/use-businesses';
import type {
  Business,
  BusinessFormInput,
  BusinessStatusKey,
  BusinessVerificationStatusKey,
} from '@/lib/businesses/types';

const verificationTone: Record<BusinessVerificationStatusKey, 'success' | 'warning' | 'danger' | 'neutral'> = {
  unverified: 'neutral',
  pending: 'warning',
  verified: 'success',
  rejected: 'danger',
};

const statusTransitions: Record<BusinessStatusKey, BusinessStatusKey[]> = {
  active: ['inactive', 'suspended', 'closed'],
  inactive: ['active', 'closed'],
  suspended: ['active', 'closed'],
  closed: [],
};

const statusLabels: Record<BusinessStatusKey, string> = {
  active: 'Active',
  inactive: 'Inactive',
  suspended: 'Suspended',
  closed: 'Closed',
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

function toFormInput(business: Business): BusinessFormInput {
  return {
    business_name: business.business_name,
    registered_business_name: business.registered_business_name ?? '',
    cac_registration_number: business.cac_registration_number ?? '',
    business_type: business.business_type,
    business_description: business.business_description ?? '',
    business_category_id: business.category?.id ?? '',
    business_subcategory_id: business.subcategory?.id ?? '',
    business_phone: business.business_phone ?? '',
    business_email: business.business_email ?? '',
    business_address: business.business_address ?? '',
    city: business.city ?? '',
    state: business.state ?? '',
    country: business.country ?? '',
    business_website: business.business_website ?? '',
    year_established: business.year_established ?? '',
    number_of_employees: business.number_of_employees ?? '',
    estimated_monthly_revenue: business.estimated_monthly_revenue?.amount ?? '',
    estimated_monthly_expenses: business.estimated_monthly_expenses?.amount ?? '',
    average_monthly_sales: business.average_monthly_sales?.amount ?? '',
  };
}

export default function BusinessDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: business, isLoading, error } = useBusiness(id);
  const canUpdateBusiness = useHasPermission('businesses.update');

  const verify = useVerifyBusiness(id);
  const rejectVerification = useRejectBusinessVerification(id);
  const changeStatus = useChangeBusinessStatus(id);

  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<BusinessFormInput | null>(null);

  const { data: typeOptions } = useBusinessTypeOptions();
  const { data: categoryOptions } = useCategoryOptions();
  const updateBusiness = useUpdateBusiness(id);
  const updateError = updateBusiness.error instanceof ApiError ? updateBusiness.error : null;

  const subcategoryOptions = useMemo(() => {
    const category = categoryOptions?.find(
      (option) => String(option.value) === String(form?.business_category_id),
    );

    return category?.children ?? [];
  }, [categoryOptions, form?.business_category_id]);

  function startEditing(current: Business) {
    setForm(toFormInput(current));
    setEditing(true);
  }

  function set<K extends keyof BusinessFormInput>(key: K, value: BusinessFormInput[K]) {
    if (!form) return;
    setForm({ ...form, [key]: value });
  }

  async function handleSave(event: React.FormEvent) {
    event.preventDefault();
    if (!form) return;

    try {
      await updateBusiness.mutateAsync(form);
      setEditing(false);
    } catch {
      // Surfaced via `updateError` above, rendered from the mutation state.
    }
  }

  return (
    <QueryState isLoading={isLoading} error={error}>
      {business ? (
        <div className="space-y-6">
          <PageHeader
            title={business.business_name}
            description={`${business.business_number} · ${business.business_type_label}`}
            actions={
              <div className="flex items-center gap-2">
                <Badge tone={verificationTone[business.verification_status]}>
                  {business.verification_status_label}
                </Badge>
                <Badge tone="neutral">{business.status_label}</Badge>
                {canUpdateBusiness && !editing ? (
                  <Button type="button" variant="secondary" onClick={() => startEditing(business)}>
                    Edit
                  </Button>
                ) : null}
              </div>
            }
          />

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Workflow</h2>
            <div className="flex flex-wrap items-start gap-3">
              {!business.is_verified ? (
                <ActionButton label="Verify" onConfirm={() => verify.mutateAsync()} />
              ) : null}

              {business.verification_status !== 'rejected' && !business.is_verified ? (
                <ReasonActionButton
                  label="Reject verification"
                  reasonLabel="Reason for rejection"
                  onConfirm={(reason) => rejectVerification.mutateAsync({ reason })}
                />
              ) : null}

              {statusTransitions[business.status].map((target) => (
                <ReasonActionButton
                  key={target}
                  label={`Mark as ${statusLabels[target]}`}
                  variant={target === 'closed' || target === 'suspended' ? 'destructive' : 'secondary'}
                  reasonLabel="Reason"
                  onConfirm={(reason) => changeStatus.mutateAsync({ status: target, reason })}
                />
              ))}
            </div>
          </Card>

          {editing && form ? (
            <Card>
              <div className="mb-4 flex items-center justify-between">
                <h2 className="text-sm font-semibold text-slate-900">Edit business details</h2>
                <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(false)}>
                  Cancel
                </Button>
              </div>
              <form onSubmit={handleSave} className="space-y-5">
                {updateError && !updateError.isValidation ? (
                  <Alert tone="error" reference={updateError.correlationId}>
                    {updateError.message}
                  </Alert>
                ) : null}

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field
                    label="Business name"
                    required
                    value={form.business_name}
                    onChange={(event) => set('business_name', event.target.value)}
                    error={updateError?.fieldError('business_name')}
                  />
                  <Field
                    label="Registered business name"
                    value={form.registered_business_name}
                    onChange={(event) => set('registered_business_name', event.target.value)}
                    error={updateError?.fieldError('registered_business_name')}
                  />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <SelectField
                    label="Business type"
                    required
                    placeholder="Select a business type"
                    options={(typeOptions ?? []).map((option) => ({ value: option.value, label: option.label }))}
                    value={form.business_type}
                    onChange={(event) => set('business_type', event.target.value)}
                    error={updateError?.fieldError('business_type')}
                  />
                  <Field
                    label="CAC registration number"
                    value={form.cac_registration_number}
                    onChange={(event) => set('cac_registration_number', event.target.value)}
                    error={updateError?.fieldError('cac_registration_number')}
                  />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <SelectField
                    label="Category"
                    placeholder="Select a category"
                    options={(categoryOptions ?? []).map((option) => ({
                      value: String(option.value),
                      label: option.label,
                    }))}
                    value={String(form.business_category_id)}
                    onChange={(event) => {
                      set('business_category_id', event.target.value);
                      set('business_subcategory_id', '');
                    }}
                    error={updateError?.fieldError('business_category_id')}
                  />
                  <SelectField
                    label="Subcategory"
                    placeholder="Select a subcategory"
                    options={subcategoryOptions.map((option) => ({
                      value: String(option.value),
                      label: option.label,
                    }))}
                    value={String(form.business_subcategory_id)}
                    onChange={(event) => set('business_subcategory_id', event.target.value)}
                    error={updateError?.fieldError('business_subcategory_id')}
                    disabled={subcategoryOptions.length === 0}
                  />
                </div>

                <Field
                  label="Description"
                  value={form.business_description}
                  onChange={(event) => set('business_description', event.target.value)}
                  error={updateError?.fieldError('business_description')}
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field
                    label="Business phone"
                    value={form.business_phone}
                    onChange={(event) => set('business_phone', event.target.value)}
                    error={updateError?.fieldError('business_phone')}
                  />
                  <Field
                    label="Business email"
                    type="email"
                    value={form.business_email}
                    onChange={(event) => set('business_email', event.target.value)}
                    error={updateError?.fieldError('business_email')}
                  />
                </div>

                <Field
                  label="Business address"
                  value={form.business_address}
                  onChange={(event) => set('business_address', event.target.value)}
                  error={updateError?.fieldError('business_address')}
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <Field
                    label="City"
                    value={form.city}
                    onChange={(event) => set('city', event.target.value)}
                    error={updateError?.fieldError('city')}
                  />
                  <Field
                    label="State"
                    value={form.state}
                    onChange={(event) => set('state', event.target.value)}
                    error={updateError?.fieldError('state')}
                  />
                  <Field
                    label="Country"
                    value={form.country}
                    onChange={(event) => set('country', event.target.value)}
                    error={updateError?.fieldError('country')}
                  />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field
                    label="Website"
                    value={form.business_website}
                    onChange={(event) => set('business_website', event.target.value)}
                    error={updateError?.fieldError('business_website')}
                  />
                  <Field
                    label="Year established"
                    type="number"
                    value={form.year_established}
                    onChange={(event) => set('year_established', event.target.value)}
                    error={updateError?.fieldError('year_established')}
                  />
                </div>

                <Field
                  label="Number of employees"
                  type="number"
                  value={form.number_of_employees}
                  onChange={(event) => set('number_of_employees', event.target.value)}
                  error={updateError?.fieldError('number_of_employees')}
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <Field
                    label="Estimated monthly revenue"
                    placeholder="0.00"
                    value={form.estimated_monthly_revenue}
                    onChange={(event) => set('estimated_monthly_revenue', event.target.value)}
                    error={updateError?.fieldError('estimated_monthly_revenue')}
                  />
                  <Field
                    label="Estimated monthly expenses"
                    placeholder="0.00"
                    value={form.estimated_monthly_expenses}
                    onChange={(event) => set('estimated_monthly_expenses', event.target.value)}
                    error={updateError?.fieldError('estimated_monthly_expenses')}
                  />
                  <Field
                    label="Average monthly sales"
                    placeholder="0.00"
                    value={form.average_monthly_sales}
                    onChange={(event) => set('average_monthly_sales', event.target.value)}
                    error={updateError?.fieldError('average_monthly_sales')}
                  />
                </div>

                <Button type="submit" loading={updateBusiness.isPending}>
                  Save changes
                </Button>
              </form>
            </Card>
          ) : null}

          {!editing ? (
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              <Card>
                <h2 className="mb-4 text-sm font-semibold text-slate-900">Details</h2>
                <div className="grid grid-cols-2 gap-4">
                  <Detail label="Registered name" value={business.registered_business_name} />
                  <Detail label="CAC number" value={business.cac_registration_number} />
                  <Detail label="Category" value={business.category?.name} />
                  <Detail label="Subcategory" value={business.subcategory?.name} />
                  <Detail label="Phone" value={business.business_phone} />
                  <Detail label="Email" value={business.business_email} />
                  <Detail
                    label="Address"
                    value={[business.business_address, business.city, business.state, business.country]
                      .filter(Boolean)
                      .join(', ')}
                  />
                  <Detail label="Website" value={business.business_website} />
                  <Detail label="Year established" value={business.year_established} />
                  <Detail label="Employees" value={business.number_of_employees} />
                </div>
              </Card>

              <Card>
                <h2 className="mb-4 text-sm font-semibold text-slate-900">Financials</h2>
                <div className="grid grid-cols-2 gap-4">
                  <Detail label="Estimated monthly revenue" value={formatMoney(business.estimated_monthly_revenue)} />
                  <Detail label="Estimated monthly expenses" value={formatMoney(business.estimated_monthly_expenses)} />
                  <Detail label="Average monthly sales" value={formatMoney(business.average_monthly_sales)} />
                  <Detail label="Declared monthly surplus" value={formatMoney(business.declared_monthly_surplus)} />
                </div>

                {business.business_description ? (
                  <>
                    <h2 className="mt-6 mb-2 text-sm font-semibold text-slate-900">Description</h2>
                    <p className="text-sm text-slate-700">{business.business_description}</p>
                  </>
                ) : null}
              </Card>
            </div>
          ) : null}

          <Link href={`/merchants/${business.merchant_id}`} className="text-sm text-brand-700 hover:underline">
            View merchant
          </Link>

          <p className="text-xs text-slate-400">Last updated {formatDateTime(business.updated_at)}</p>
        </div>
      ) : null}
    </QueryState>
  );
}
