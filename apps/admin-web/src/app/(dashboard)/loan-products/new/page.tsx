'use client';

import { ApiError } from '@naipay/api-client';
import { useRouter } from 'next/navigation';
import { useState } from 'react';

import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { LoanProductForm } from '@/components/loan-products/loan-product-form';
import { RequirePermission } from '@/components/auth/require-permission';
import { useCreateLoanProduct } from '@/lib/loan-products/use-loan-products';
import type { LoanProductFormInput } from '@/lib/loan-products/types';

const emptyForm: LoanProductFormInput = {
  code: '',
  name: '',
  description: '',
  minimum_amount: '',
  maximum_amount: '',
  minimum_tenor: '',
  maximum_tenor: '',
  default_tenor: '',
  tenor_unit: 'days',
  interest_method: 'flat',
  interest_rate: '',
  interest_period: '',
  repayment_frequency: 'daily',
  processing_fee_type: 'none',
  processing_fee_value: '',
  insurance_fee_type: 'none',
  insurance_fee_value: '',
  late_payment_penalty_type: 'none',
  late_payment_penalty_value: '',
  grace_period_days: 0,
  requires_guarantor: false,
  minimum_guarantors: 0,
  requires_collateral: false,
  display_order: 0,
};

export default function NewLoanProductPage() {
  return (
    <RequirePermission permission="loan_products.manage">
      <NewLoanProductForm />
    </RequirePermission>
  );
}

function NewLoanProductForm() {
  const router = useRouter();
  const [form, setForm] = useState<LoanProductFormInput>(emptyForm);
  const createProduct = useCreateLoanProduct();
  const error = createProduct.error instanceof ApiError ? createProduct.error : null;

  async function handleSubmit() {
    try {
      const product = await createProduct.mutateAsync(form);
      router.replace(`/loan-products/${product.id}`);
    } catch {
      // Surfaced via `error` above, rendered from the mutation state.
    }
  }

  return (
    <>
      <PageHeader
        title="New loan product"
        description="Defines pricing and terms for a product that can then be sold on applications."
      />

      <Card className="max-w-4xl">
        <LoanProductForm
          mode="create"
          form={form}
          onChange={setForm}
          onSubmit={handleSubmit}
          submitting={createProduct.isPending}
          error={error}
        />
      </Card>
    </>
  );
}
