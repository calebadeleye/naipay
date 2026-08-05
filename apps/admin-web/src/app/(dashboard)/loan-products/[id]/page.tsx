'use client';

import { ApiError } from '@naipay/api-client';
import { useParams } from 'next/navigation';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { ReasonActionButton } from '@/components/ui/workflow-action';
import { LoanProductForm } from '@/components/loan-products/loan-product-form';
import { formatAmountString, formatDateTime, formatMoney } from '@/lib/format';
import {
  useChangeLoanProductStatus,
  useLoanProduct,
  usePreviewLoanProduct,
  useUpdateLoanProduct,
} from '@/lib/loan-products/use-loan-products';
import type { LoanProduct, LoanProductFormInput, SchedulePreview } from '@/lib/loan-products/types';

function toFormInput(product: LoanProduct): LoanProductFormInput {
  return {
    code: product.code,
    name: product.name,
    description: product.description ?? '',
    minimum_amount: product.amount.minimum.amount,
    maximum_amount: product.amount.maximum.amount,
    minimum_tenor: product.tenor.minimum,
    maximum_tenor: product.tenor.maximum,
    default_tenor: product.tenor.default ?? '',
    tenor_unit: product.tenor.unit,
    interest_method: product.interest.method,
    interest_rate: product.interest.rate,
    interest_period: product.interest.period ?? '',
    repayment_frequency: product.repayment.frequency,
    processing_fee_type: product.fees.processing.type,
    processing_fee_value: product.fees.processing.value?.amount ?? '',
    insurance_fee_type: product.fees.insurance.type,
    insurance_fee_value: product.fees.insurance.value?.amount ?? '',
    late_payment_penalty_type: product.fees.late_payment_penalty.type,
    late_payment_penalty_value: product.fees.late_payment_penalty.value?.amount ?? '',
    grace_period_days: product.repayment.grace_period_days,
    requires_guarantor: product.requirements.requires_guarantor,
    minimum_guarantors: product.requirements.minimum_guarantors,
    requires_collateral: product.requirements.requires_collateral,
    display_order: product.display_order,
  };
}

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

function SchedulePreviewPanel({ productId }: { productId: number | string }) {
  const [amount, setAmount] = useState('');
  const [tenor, setTenor] = useState('');
  const [result, setResult] = useState<SchedulePreview | null>(null);
  const preview = usePreviewLoanProduct(productId);
  const error = preview.error instanceof ApiError ? preview.error : null;

  async function handlePreview() {
    try {
      const data = await preview.mutateAsync({ amount, tenor: Number(tenor) });
      setResult(data);
    } catch {
      // Surfaced via `error` above, rendered from the mutation state.
    }
  }

  return (
    <Card>
      <h2 className="mb-4 text-sm font-semibold text-slate-900">Schedule preview</h2>
      <div className="flex flex-wrap items-end gap-3">
        <Field label="Amount" placeholder="0.00" value={amount} onChange={(event) => setAmount(event.target.value)} className="w-40" />
        <Field label="Tenor" type="number" value={tenor} onChange={(event) => setTenor(event.target.value)} className="w-28" />
        <Button type="button" onClick={handlePreview} loading={preview.isPending} disabled={!amount || !tenor}>
          Preview
        </Button>
      </div>

      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}

      {result ? (
        <div className="mt-4 space-y-4">
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <Detail label="Total interest" value={formatMoney(result.summary.total_interest)} />
            <Detail label="Total fees" value={formatMoney(result.summary.total_fees)} />
            <Detail label="Total payable" value={formatMoney(result.summary.total_payable)} />
            <Detail label="Net disbursement" value={formatMoney(result.summary.net_disbursement)} />
          </div>

          <div className="overflow-x-auto rounded-md border border-slate-200">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-3 py-2">#</th>
                  <th className="px-3 py-2">Due date</th>
                  <th className="px-3 py-2 text-right">Principal</th>
                  <th className="px-3 py-2 text-right">Interest</th>
                  <th className="px-3 py-2 text-right">Fees</th>
                  <th className="px-3 py-2 text-right">Total due</th>
                </tr>
              </thead>
              <tbody>
                {result.schedule.map((instalment) => (
                  <tr key={instalment.installment_number} className="border-b border-slate-100 last:border-0">
                    <td className="px-3 py-2 text-slate-500">{instalment.installment_number}</td>
                    <td className="px-3 py-2 text-slate-700">{instalment.due_date}</td>
                    <td className="numeric px-3 py-2 text-right text-slate-700">
                      {formatAmountString(instalment.principal_due)}
                    </td>
                    <td className="numeric px-3 py-2 text-right text-slate-700">
                      {formatAmountString(instalment.interest_due)}
                    </td>
                    <td className="numeric px-3 py-2 text-right text-slate-700">
                      {formatAmountString(instalment.fee_due)}
                    </td>
                    <td className="numeric px-3 py-2 text-right font-medium text-slate-900">
                      {formatAmountString(instalment.total_due)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ) : null}
    </Card>
  );
}

export default function LoanProductDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: product, isLoading, error } = useLoanProduct(id);
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<LoanProductFormInput | null>(null);

  const updateProduct = useUpdateLoanProduct(id);
  const changeStatus = useChangeLoanProductStatus(id);
  const updateError = updateProduct.error instanceof ApiError ? updateProduct.error : null;

  function startEditing(current: LoanProduct) {
    setForm(toFormInput(current));
    setEditing(true);
  }

  async function handleSave() {
    if (!form) return;

    try {
      await updateProduct.mutateAsync(form);
      setEditing(false);
    } catch {
      // Surfaced via `updateError` above, rendered from the mutation state.
    }
  }

  return (
    <QueryState isLoading={isLoading} error={error}>
      {product ? (
        <div className="space-y-6">
          <PageHeader
            title={product.name}
            description={`${product.code} · ${product.summary}`}
            actions={
              <div className="flex items-center gap-2">
                <Badge tone={product.is_active ? 'success' : 'neutral'}>
                  {product.is_active ? 'Active' : 'Retired'}
                </Badge>
                {!editing ? (
                  <Button type="button" variant="secondary" onClick={() => startEditing(product)}>
                    Edit
                  </Button>
                ) : null}
              </div>
            }
          />

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Workflow</h2>
            <div className="flex flex-wrap items-start gap-3">
              {product.status === 'active' ? (
                <ReasonActionButton
                  label="Retire"
                  reasonLabel="Reason for retiring this product"
                  onConfirm={(reason) => changeStatus.mutateAsync({ status: 'retired', reason })}
                />
              ) : (
                <ReasonActionButton
                  label="Reactivate"
                  variant="secondary"
                  reasonLabel="Reason for reactivating this product"
                  onConfirm={(reason) => changeStatus.mutateAsync({ status: 'active', reason })}
                />
              )}
            </div>
            <p className="mt-3 text-xs text-slate-500">
              There is no delete: loans already booked under this product keep the terms they were sold on.
            </p>
          </Card>

          {editing && form ? (
            <Card className="max-w-4xl">
              <div className="mb-4 flex items-center justify-between">
                <h2 className="text-sm font-semibold text-slate-900">Edit product</h2>
                <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(false)}>
                  Cancel
                </Button>
              </div>
              <LoanProductForm
                mode="edit"
                form={form}
                onChange={setForm}
                onSubmit={handleSave}
                submitting={updateProduct.isPending}
                error={updateError}
              />
            </Card>
          ) : (
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              <Card>
                <h2 className="mb-4 text-sm font-semibold text-slate-900">Terms</h2>
                <div className="grid grid-cols-2 gap-4">
                  <Detail
                    label="Amount range"
                    value={`${formatMoney(product.amount.minimum)} – ${formatMoney(product.amount.maximum)}`}
                  />
                  <Detail
                    label="Tenor range"
                    value={`${product.tenor.minimum}–${product.tenor.maximum} ${product.tenor.unit_label.toLowerCase()}`}
                  />
                  <Detail label="Default tenor" value={product.tenor.default} />
                  <Detail label="Repayment frequency" value={product.repayment.frequency_label} />
                  <Detail label="Grace period" value={`${product.repayment.grace_period_days} days`} />
                  <Detail label="Display order" value={product.display_order} />
                </div>
              </Card>

              <Card>
                <h2 className="mb-4 text-sm font-semibold text-slate-900">Interest</h2>
                <div className="grid grid-cols-2 gap-4">
                  <Detail label="Method" value={product.interest.method_label} />
                  <Detail label="Rate" value={`${product.interest.rate}%`} />
                  <Detail label="Period" value={product.interest.period} />
                  <Detail label="Varies with term" value={product.interest.varies_with_term ? 'Yes' : 'No'} />
                </div>
                <p className="mt-3 text-sm text-slate-600">{product.interest.method_description}</p>
              </Card>

              <Card>
                <h2 className="mb-4 text-sm font-semibold text-slate-900">Fees</h2>
                <div className="grid grid-cols-3 gap-4">
                  <Detail
                    label="Processing"
                    value={
                      product.fees.processing.type === 'none'
                        ? 'None'
                        : `${formatMoney(product.fees.processing.value)}`
                    }
                  />
                  <Detail
                    label="Insurance"
                    value={
                      product.fees.insurance.type === 'none' ? 'None' : `${formatMoney(product.fees.insurance.value)}`
                    }
                  />
                  <Detail
                    label="Late payment penalty"
                    value={
                      product.fees.late_payment_penalty.type === 'none'
                        ? 'None'
                        : `${formatMoney(product.fees.late_payment_penalty.value)}`
                    }
                  />
                </div>
              </Card>

              <Card>
                <h2 className="mb-4 text-sm font-semibold text-slate-900">Requirements</h2>
                <div className="grid grid-cols-3 gap-4">
                  <Detail label="Guarantor required" value={product.requirements.requires_guarantor ? 'Yes' : 'No'} />
                  <Detail label="Minimum guarantors" value={product.requirements.minimum_guarantors} />
                  <Detail label="Collateral required" value={product.requirements.requires_collateral ? 'Yes' : 'No'} />
                </div>
              </Card>
            </div>
          )}

          <SchedulePreviewPanel productId={product.id} />

          <p className="text-xs text-slate-400">Last updated {formatDateTime(product.updated_at)}</p>
        </div>
      ) : null}
    </QueryState>
  );
}
