'use client';

import { ApiError } from '@naipay/api-client';
import { useParams, useRouter } from 'next/navigation';
import { useMemo, useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select';
import { QueryState } from '@/components/ui/query-state';
import {
  useBusinessTypeOptions,
  useCategoryOptions,
  useCreateBusiness,
} from '@/lib/businesses/use-businesses';
import type { BusinessFormInput } from '@/lib/businesses/types';

const emptyForm: BusinessFormInput = {
  business_name: '',
  registered_business_name: '',
  cac_registration_number: '',
  business_type: '',
  business_description: '',
  business_category_id: '',
  business_subcategory_id: '',
  business_phone: '',
  business_email: '',
  business_address: '',
  city: '',
  state: '',
  country: 'Nigeria',
  website: '',
  year_established: '',
  number_of_employees: '',
  estimated_monthly_revenue: '',
  estimated_monthly_expenses: '',
  average_monthly_sales: '',
};

export default function NewBusinessPage() {
  const params = useParams<{ id: string }>();
  const merchantId = params.id;
  const router = useRouter();

  const [form, setForm] = useState<BusinessFormInput>(emptyForm);

  const { data: typeOptions, isLoading: typesLoading, error: typesError } = useBusinessTypeOptions();
  const { data: categoryOptions, isLoading: categoriesLoading, error: categoriesError } = useCategoryOptions();
  const createBusiness = useCreateBusiness(merchantId);
  const error = createBusiness.error instanceof ApiError ? createBusiness.error : null;

  const subcategoryOptions = useMemo(() => {
    const category = categoryOptions?.find(
      (option) => String(option.value) === String(form.business_category_id),
    );

    return category?.children ?? [];
  }, [categoryOptions, form.business_category_id]);

  function set<K extends keyof BusinessFormInput>(key: K, value: BusinessFormInput[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();

    const business = await createBusiness.mutateAsync(form);
    router.replace(`/businesses/${business.id}`);
  }

  return (
    <>
      <PageHeader
        title="New business"
        description="Registers a business under this merchant. Verification happens separately, once the details are reviewed."
      />

      <QueryState isLoading={typesLoading || categoriesLoading} error={typesError || categoriesError}>
        <Card className="max-w-3xl">
          <form onSubmit={handleSubmit} className="space-y-5">
            {error && !error.isValidation ? (
              <Alert tone="error" reference={error.correlationId}>
                {error.message}
              </Alert>
            ) : null}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field
                label="Business name"
                required
                value={form.business_name}
                onChange={(event) => set('business_name', event.target.value)}
                error={error?.fieldError('business_name')}
              />
              <Field
                label="Registered business name"
                value={form.registered_business_name}
                onChange={(event) => set('registered_business_name', event.target.value)}
                error={error?.fieldError('registered_business_name')}
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
                error={error?.fieldError('business_type')}
              />
              <Field
                label="CAC registration number"
                value={form.cac_registration_number}
                onChange={(event) => set('cac_registration_number', event.target.value)}
                error={error?.fieldError('cac_registration_number')}
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
                error={error?.fieldError('business_category_id')}
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
                error={error?.fieldError('business_subcategory_id')}
                disabled={subcategoryOptions.length === 0}
              />
            </div>

            <Field
              label="Description"
              value={form.business_description}
              onChange={(event) => set('business_description', event.target.value)}
              error={error?.fieldError('business_description')}
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field
                label="Business phone"
                value={form.business_phone}
                onChange={(event) => set('business_phone', event.target.value)}
                error={error?.fieldError('business_phone')}
              />
              <Field
                label="Business email"
                type="email"
                value={form.business_email}
                onChange={(event) => set('business_email', event.target.value)}
                error={error?.fieldError('business_email')}
              />
            </div>

            <Field
              label="Business address"
              value={form.business_address}
              onChange={(event) => set('business_address', event.target.value)}
              error={error?.fieldError('business_address')}
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
                label="Website"
                value={form.website}
                onChange={(event) => set('website', event.target.value)}
                error={error?.fieldError('website')}
              />
              <Field
                label="Year established"
                type="number"
                value={form.year_established}
                onChange={(event) => set('year_established', event.target.value)}
                error={error?.fieldError('year_established')}
              />
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <Field
                label="Estimated monthly revenue"
                placeholder="0.00"
                value={form.estimated_monthly_revenue}
                onChange={(event) => set('estimated_monthly_revenue', event.target.value)}
                error={error?.fieldError('estimated_monthly_revenue')}
              />
              <Field
                label="Estimated monthly expenses"
                placeholder="0.00"
                value={form.estimated_monthly_expenses}
                onChange={(event) => set('estimated_monthly_expenses', event.target.value)}
                error={error?.fieldError('estimated_monthly_expenses')}
              />
              <Field
                label="Average monthly sales"
                placeholder="0.00"
                value={form.average_monthly_sales}
                onChange={(event) => set('average_monthly_sales', event.target.value)}
                error={error?.fieldError('average_monthly_sales')}
              />
            </div>

            <div className="flex gap-3">
              <Button type="submit" loading={createBusiness.isPending}>
                Create business
              </Button>
            </div>
          </form>
        </Card>
      </QueryState>
    </>
  );
}
