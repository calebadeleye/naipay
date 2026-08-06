'use client';

import { ApiError } from '@naipay/api-client';
import { useRouter } from 'next/navigation';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select';
import { RequirePermission } from '@/components/auth/require-permission';
import { useMerchants } from '@/lib/merchants/use-merchants';
import { useBusinesses } from '@/lib/businesses/use-businesses';
import { useLoanProductOptions } from '@/lib/loan-products/use-loan-products';
import { useCreateLoanApplication } from '@/lib/loan-applications/use-loan-applications';
import type { Merchant } from '@/lib/merchants/types';

function MerchantPicker({
  selected,
  onSelect,
}: {
  selected: Merchant | null;
  onSelect: (merchant: Merchant | null) => void;
}) {
  const [search, setSearch] = useState('');
  const { data, isFetching } = useMerchants(search.length >= 2 ? { search, per_page: 5 } : undefined);

  if (selected) {
    return (
      <div className="space-y-1.5">
        <p className="block text-sm font-medium text-slate-800">Merchant</p>
        <div className="flex items-center justify-between rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm">
          <div>
            <p className="font-medium text-slate-900">{selected.full_name}</p>
            <p className="numeric text-xs text-slate-500">{selected.merchant_number}</p>
          </div>
          <Button type="button" variant="ghost" size="sm" onClick={() => onSelect(null)}>
            Change
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-1.5">
      <Field
        label="Merchant"
        placeholder="Search by name, phone or merchant number"
        value={search}
        onChange={(event) => setSearch(event.target.value)}
        hint="Type at least 2 characters to search."
      />
      {search.length >= 2 ? (
        <div className="max-h-56 overflow-y-auto rounded-md border border-slate-200">
          {isFetching ? (
            <p className="px-3 py-2 text-sm text-slate-500">Searching…</p>
          ) : data && data.items.length > 0 ? (
            data.items.map((merchant) => (
              <button
                key={merchant.id}
                type="button"
                onClick={() => onSelect(merchant)}
                className="block w-full border-b border-slate-100 px-3 py-2 text-left text-sm last:border-0 hover:bg-slate-50"
              >
                <p className="font-medium text-slate-900">{merchant.full_name}</p>
                <p className="numeric text-xs text-slate-500">
                  {merchant.merchant_number} · {merchant.phone}
                </p>
              </button>
            ))
          ) : (
            <p className="px-3 py-2 text-sm text-slate-500">No merchants found.</p>
          )}
        </div>
      ) : null}
    </div>
  );
}

export default function NewLoanApplicationPage() {
  return (
    <RequirePermission permission="loan_applications.create">
      <NewLoanApplicationForm />
    </RequirePermission>
  );
}

function NewLoanApplicationForm() {
  const router = useRouter();
  const [merchant, setMerchant] = useState<Merchant | null>(null);
  const [businessId, setBusinessId] = useState('');
  const [loanProductId, setLoanProductId] = useState('');
  const [amount, setAmount] = useState('');
  const [tenor, setTenor] = useState('');
  const [purpose, setPurpose] = useState('');

  const { data: businesses } = useBusinesses(
    merchant ? { merchant_id: merchant.id, per_page: 50 } : undefined,
  );
  const { data: productOptions } = useLoanProductOptions();
  const createApplication = useCreateLoanApplication();
  const error = createApplication.error instanceof ApiError ? createApplication.error : null;

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    if (!merchant || !businessId || !loanProductId) return;

    try {
      const application = await createApplication.mutateAsync({
        merchant_id: merchant.id,
        business_id: Number(businessId),
        loan_product_id: Number(loanProductId),
        requested_amount: amount,
        requested_tenor: Number(tenor),
        purpose: purpose || undefined,
      });

      router.replace(`/loan-applications/${application.id}`);
    } catch {
      // Surfaced via `error` above, rendered from the mutation state.
    }
  }

  return (
    <>
      <PageHeader
        title="New loan application"
        description="Starts as a draft against a merchant's business. Assessment, recommendation and approval happen afterwards."
      />

      <Card className="max-w-2xl">
        <form onSubmit={handleSubmit} className="space-y-5">
          {error && !error.isValidation ? (
            <Alert tone="error" reference={error.correlationId}>
              {error.message}
            </Alert>
          ) : null}

          <MerchantPicker selected={merchant} onSelect={setMerchant} />

          {merchant ? (
            !merchant.can_borrow ? (
              <Alert tone="warning">This merchant is not currently eligible to borrow.</Alert>
            ) : (
              <SelectField
                label="Business"
                placeholder="Select a business"
                options={(businesses?.items ?? []).map((business) => ({
                  value: String(business.id),
                  label: business.business_name,
                }))}
                value={businessId}
                onChange={(event) => setBusinessId(event.target.value)}
                error={error?.fieldError('business_id')}
                disabled={!businesses || businesses.items.length === 0}
                hint={businesses && businesses.items.length === 0 ? 'This merchant has no businesses yet.' : undefined}
              />
            )
          ) : null}

          <SelectField
            label="Loan product"
            placeholder="Select a loan product"
            options={(productOptions ?? []).map((product) => ({
              value: String(product.value),
              label: `${product.label} (${product.summary})`,
            }))}
            value={loanProductId}
            onChange={(event) => setLoanProductId(event.target.value)}
            error={error?.fieldError('loan_product_id')}
          />

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="Requested amount"
              required
              placeholder="0.00"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              error={error?.fieldError('requested_amount')}
            />
            <Field
              label="Requested tenor"
              required
              type="number"
              value={tenor}
              onChange={(event) => setTenor(event.target.value)}
              error={error?.fieldError('requested_tenor')}
            />
          </div>

          <Field
            label="Purpose"
            value={purpose}
            onChange={(event) => setPurpose(event.target.value)}
            error={error?.fieldError('purpose')}
          />

          <div className="flex gap-3">
            <Button type="submit" loading={createApplication.isPending} disabled={!merchant || !businessId || !loanProductId}>
              Create application
            </Button>
          </div>
        </form>
      </Card>
    </>
  );
}
