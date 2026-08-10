'use client';

import { ApiError } from '@naipay/api-client';
import { useRouter } from 'next/navigation';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { TextareaField } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select';
import { QueryState } from '@/components/ui/query-state';
import { useCurrentMerchant } from '@/lib/auth/use-auth';
import { useCreateLoanApplication } from '@/lib/loan-applications/use-loan-applications';
import { useLoanProducts } from '@/lib/loan-applications/use-loan-products';
import { formatMoney } from '@/lib/format';

export default function NewLoanApplicationPage() {
  const router = useRouter();
  const { data: merchant, isLoading: merchantLoading, error: merchantError } = useCurrentMerchant();
  const { data: products, isLoading: productsLoading, error: productsError } = useLoanProducts();
  const createApplication = useCreateLoanApplication();

  const [businessId, setBusinessId] = useState('');
  const [loanProductId, setLoanProductId] = useState('');
  const [amount, setAmount] = useState('');
  const [tenor, setTenor] = useState('');
  const [purpose, setPurpose] = useState('');

  const error = createApplication.error as ApiError | null;
  const selectedProduct = products?.find((product) => String(product.id) === loanProductId);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();

    const application = await createApplication
      .mutateAsync({
        business_id: businessId,
        loan_product_id: loanProductId,
        requested_amount: amount,
        requested_tenor: tenor,
        purpose: purpose || undefined,
      })
      .catch(() => null);

    if (application) {
      router.push(`/loan-applications/${application.id}`);
    }
  }

  return (
    <QueryState isLoading={merchantLoading || productsLoading} error={merchantError || productsError}>
      <PageHeader
        title="Apply for a loan"
        description="Submitted applications are reviewed by Every Merchant before a decision is made."
      />

      <Card className="max-w-2xl">
        {merchant && merchant.businesses.length === 0 ? (
          <Alert tone="warning">
            You need at least one business on your profile before you can apply for a loan. Add one from your{' '}
            <a href="/profile" className="underline">
              profile
            </a>
            .
          </Alert>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-5">
            {error && !error.isValidation ? (
              <Alert tone="error" reference={error.correlationId}>
                {error.message}
              </Alert>
            ) : null}

            <SelectField
              label="Business"
              placeholder="Select a business"
              options={(merchant?.businesses ?? []).map((business) => ({
                value: String(business.id),
                label: business.business_name,
              }))}
              value={businessId}
              onChange={(event) => setBusinessId(event.target.value)}
              error={error?.fieldError('business_id')}
            />

            <SelectField
              label="Loan product"
              placeholder="Select a loan product"
              options={(products ?? []).map((product) => ({ value: String(product.id), label: product.name }))}
              value={loanProductId}
              onChange={(event) => setLoanProductId(event.target.value)}
              error={error?.fieldError('loan_product_id')}
              hint={selectedProduct?.summary}
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field
                label="Amount requested"
                placeholder="e.g. 150000.00"
                value={amount}
                onChange={(event) => setAmount(event.target.value)}
                error={error?.fieldError('requested_amount')}
                hint={
                  selectedProduct
                    ? `Between ${formatMoney(selectedProduct.minimum_amount)} and ${formatMoney(selectedProduct.maximum_amount)}`
                    : undefined
                }
              />
              <Field
                label={`Tenor${selectedProduct ? ` (${selectedProduct.tenor_unit_label.toLowerCase()})` : ''}`}
                type="number"
                value={tenor}
                onChange={(event) => setTenor(event.target.value)}
                error={error?.fieldError('requested_tenor')}
                hint={
                  selectedProduct
                    ? `Between ${selectedProduct.minimum_tenor} and ${selectedProduct.maximum_tenor}`
                    : undefined
                }
              />
            </div>

            <TextareaField
              label="What is this loan for?"
              value={purpose}
              onChange={(event) => setPurpose(event.target.value)}
              error={error?.fieldError('purpose')}
            />

            <Button type="submit" loading={createApplication.isPending}>
              Submit application
            </Button>
          </form>
        )}
      </Card>
    </QueryState>
  );
}
