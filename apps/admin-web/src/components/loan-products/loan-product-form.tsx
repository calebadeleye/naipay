'use client';

import { ApiError } from '@naipay/api-client';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select';
import type { LoanProductFormInput } from '@/lib/loan-products/types';

const tenorUnitOptions = [
  { value: 'days', label: 'Days' },
  { value: 'weeks', label: 'Weeks' },
  { value: 'months', label: 'Months' },
];

const interestMethodOptions = [
  { value: 'flat', label: 'Flat rate' },
  { value: 'reducing_balance', label: 'Reducing balance' },
  { value: 'declining_balance', label: 'Declining balance' },
  { value: 'simple_interest', label: 'Simple interest' },
];

const repaymentFrequencyOptions = [
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'monthly', label: 'Monthly' },
];

const feeTypeOptions = [
  { value: 'none', label: 'None' },
  { value: 'fixed', label: 'Fixed amount' },
  { value: 'percentage', label: 'Percentage of principal' },
];

interface LoanProductFormProps {
  mode: 'create' | 'edit';
  form: LoanProductFormInput;
  onChange: (form: LoanProductFormInput) => void;
  onSubmit: () => Promise<unknown>;
  submitting: boolean;
  error: ApiError | null;
}

export function LoanProductForm({ mode, form, onChange, onSubmit, submitting, error }: LoanProductFormProps) {
  function set<K extends keyof LoanProductFormInput>(key: K, value: LoanProductFormInput[K]) {
    onChange({ ...form, [key]: value });
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    await onSubmit();
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-5">
      {error && !error.isValidation ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field
          label="Code"
          required
          disabled={mode === 'edit'}
          value={form.code}
          onChange={(event) => set('code', event.target.value)}
          error={error?.fieldError('code')}
          hint={mode === 'edit' ? 'Cannot be changed once created.' : undefined}
        />
        <Field
          label="Name"
          required
          value={form.name}
          onChange={(event) => set('name', event.target.value)}
          error={error?.fieldError('name')}
        />
      </div>

      <Field
        label="Description"
        value={form.description}
        onChange={(event) => set('description', event.target.value)}
        error={error?.fieldError('description')}
      />

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field
          label="Minimum amount"
          required
          placeholder="0.00"
          value={form.minimum_amount}
          onChange={(event) => set('minimum_amount', event.target.value)}
          error={error?.fieldError('minimum_amount')}
        />
        <Field
          label="Maximum amount"
          required
          placeholder="0.00"
          value={form.maximum_amount}
          onChange={(event) => set('maximum_amount', event.target.value)}
          error={error?.fieldError('maximum_amount')}
        />
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
        <Field
          label="Minimum tenor"
          required
          type="number"
          value={form.minimum_tenor}
          onChange={(event) => set('minimum_tenor', event.target.value)}
          error={error?.fieldError('minimum_tenor')}
        />
        <Field
          label="Maximum tenor"
          required
          type="number"
          value={form.maximum_tenor}
          onChange={(event) => set('maximum_tenor', event.target.value)}
          error={error?.fieldError('maximum_tenor')}
        />
        <Field
          label="Default tenor"
          type="number"
          value={form.default_tenor}
          onChange={(event) => set('default_tenor', event.target.value)}
          error={error?.fieldError('default_tenor')}
        />
        <SelectField
          label="Tenor unit"
          options={tenorUnitOptions}
          value={form.tenor_unit}
          onChange={(event) => set('tenor_unit', event.target.value)}
          error={error?.fieldError('tenor_unit')}
        />
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <SelectField
          label="Interest method"
          options={interestMethodOptions}
          value={form.interest_method}
          onChange={(event) => set('interest_method', event.target.value)}
          error={error?.fieldError('interest_method')}
        />
        <Field
          label="Interest rate (%)"
          required
          placeholder="20"
          value={form.interest_rate}
          onChange={(event) => set('interest_rate', event.target.value)}
          error={error?.fieldError('interest_rate')}
          hint="e.g. 20 for 20%, per the product's interest period."
        />
        <Field
          label="Interest period"
          placeholder="e.g. per annum"
          value={form.interest_period}
          onChange={(event) => set('interest_period', event.target.value)}
          error={error?.fieldError('interest_period')}
        />
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <SelectField
          label="Repayment frequency"
          options={repaymentFrequencyOptions}
          value={form.repayment_frequency}
          onChange={(event) => set('repayment_frequency', event.target.value)}
          error={error?.fieldError('repayment_frequency')}
        />
        <Field
          label="Grace period (days)"
          type="number"
          value={form.grace_period_days}
          onChange={(event) => set('grace_period_days', event.target.value)}
          error={error?.fieldError('grace_period_days')}
        />
      </div>

      <div className="rounded-md border border-slate-200 p-4">
        <h3 className="mb-3 text-sm font-semibold text-slate-900">Fees</h3>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div className="space-y-4">
            <SelectField
              label="Processing fee"
              options={feeTypeOptions}
              value={form.processing_fee_type}
              onChange={(event) => set('processing_fee_type', event.target.value)}
              error={error?.fieldError('processing_fee_type')}
            />
            <Field
              label="Processing fee value"
              value={form.processing_fee_value}
              onChange={(event) => set('processing_fee_value', event.target.value)}
              error={error?.fieldError('processing_fee_value')}
              disabled={form.processing_fee_type === 'none'}
            />
          </div>
          <div className="space-y-4">
            <SelectField
              label="Insurance fee"
              options={feeTypeOptions}
              value={form.insurance_fee_type}
              onChange={(event) => set('insurance_fee_type', event.target.value)}
              error={error?.fieldError('insurance_fee_type')}
            />
            <Field
              label="Insurance fee value"
              value={form.insurance_fee_value}
              onChange={(event) => set('insurance_fee_value', event.target.value)}
              error={error?.fieldError('insurance_fee_value')}
              disabled={form.insurance_fee_type === 'none'}
            />
          </div>
          <div className="space-y-4">
            <SelectField
              label="Late payment penalty"
              options={feeTypeOptions}
              value={form.late_payment_penalty_type}
              onChange={(event) => set('late_payment_penalty_type', event.target.value)}
              error={error?.fieldError('late_payment_penalty_type')}
            />
            <Field
              label="Penalty value"
              value={form.late_payment_penalty_value}
              onChange={(event) => set('late_payment_penalty_value', event.target.value)}
              error={error?.fieldError('late_payment_penalty_value')}
              disabled={form.late_payment_penalty_type === 'none'}
            />
          </div>
        </div>
      </div>

      <div className="rounded-md border border-slate-200 p-4">
        <h3 className="mb-3 text-sm font-semibold text-slate-900">Requirements</h3>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <label className="flex items-center gap-2 text-sm text-slate-800">
            <input
              type="checkbox"
              className="size-4 rounded border-slate-300"
              checked={form.requires_guarantor}
              onChange={(event) => set('requires_guarantor', event.target.checked)}
            />
            Requires guarantor
          </label>
          <Field
            label="Minimum guarantors"
            type="number"
            value={form.minimum_guarantors}
            onChange={(event) => set('minimum_guarantors', event.target.value)}
            error={error?.fieldError('minimum_guarantors')}
            disabled={!form.requires_guarantor}
          />
          <label className="flex items-center gap-2 text-sm text-slate-800">
            <input
              type="checkbox"
              className="size-4 rounded border-slate-300"
              checked={form.requires_collateral}
              onChange={(event) => set('requires_collateral', event.target.checked)}
            />
            Requires collateral
          </label>
        </div>
      </div>

      <Field
        label="Display order"
        type="number"
        value={form.display_order}
        onChange={(event) => set('display_order', event.target.value)}
        error={error?.fieldError('display_order')}
        hint="Lower numbers appear first in product pickers."
      />

      <div className="flex gap-3">
        <Button type="submit" loading={submitting}>
          {mode === 'create' ? 'Create loan product' : 'Save changes'}
        </Button>
      </div>
    </form>
  );
}
