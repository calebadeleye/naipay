'use client';

import { RotateCcw, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field } from '@/components/ui/field';
import { SelectField } from '@/components/ui/select';
import { useBranchOptions } from '@/lib/branches/use-branches';
import { cn } from '@/lib/cn';
import { useLoanProductOptions } from '@/lib/loan-products/use-loan-products';
import {
  activeFilterCount,
  DEFAULT_PORTFOLIO_FILTERS,
  type PortfolioFilterState,
  type PortfolioRangeKey,
} from '@/lib/reports/portfolio-analytics';
import { useStaffList } from '@/lib/staff/use-staff';

const RANGE_OPTIONS: { value: PortfolioRangeKey; label: string }[] = [
  { value: 'today', label: 'Today' },
  { value: 'yesterday', label: 'Yesterday' },
  { value: 'this_week', label: 'This week' },
  { value: 'last_week', label: 'Last week' },
  { value: 'this_month', label: 'This month' },
  { value: 'last_month', label: 'Last month' },
  { value: 'this_quarter', label: 'This quarter' },
  { value: 'last_quarter', label: 'Last quarter' },
  { value: 'this_year', label: 'This year' },
  { value: 'last_year', label: 'Last year' },
  { value: 'all_time', label: 'All time' },
  { value: 'custom', label: 'Custom range…' },
];

const STATUS_OPTIONS = [
  { value: '', label: 'Any status' },
  { value: 'pending_approval', label: 'Pending approval' },
  { value: 'pending_disbursement', label: 'Pending disbursement' },
  { value: 'disbursed', label: 'Disbursed / active' },
  { value: 'written_off', label: 'Written off' },
];

const REPAYMENT_STATUS_OPTIONS = [
  { value: '', label: 'Any' },
  { value: 'current', label: 'Current (no arrears)' },
  { value: 'overdue', label: 'Overdue' },
];

interface Props {
  value: PortfolioFilterState;
  onApply: (next: PortfolioFilterState) => void;
  isFetching: boolean;
}

export function PortfolioFilters({ value, onApply, isFetching }: Props) {
  const [draft, setDraft] = useState<PortfolioFilterState>(value);
  const [committed, setCommitted] = useState<PortfolioFilterState>(value);
  const [advancedOpen, setAdvancedOpen] = useState(
    Boolean(value.branch_id || value.disbursement_channel_id || value.borrower_id || value.loan_id || value.repayment_status || value.min_days_past_due),
  );

  // Re-seed the draft when the committed filters change from elsewhere
  // (browser back/forward, a drill-down link, "Clear"). `value` is referentially
  // stable between renders — it only changes when the URL does — so this is the
  // "adjust state during render" pattern, not an effect.
  if (value !== committed) {
    setCommitted(value);
    setDraft(value);
  }

  const products = useLoanProductOptions();
  const branches = useBranchOptions();
  const officers = useStaffList({ per_page: 200 });

  const dirty = JSON.stringify(draft) !== JSON.stringify(value);
  const activeCount = activeFilterCount(value);

  function set<K extends keyof PortfolioFilterState>(key: K, raw: PortfolioFilterState[K] | '' | undefined) {
    setDraft((current) => {
      const next = { ...current };
      if (raw === '' || raw === undefined) {
        delete next[key];
      } else {
        next[key] = raw;
      }
      return next;
    });
  }

  function numeric(key: keyof PortfolioFilterState, raw: string) {
    set(key, raw === '' ? '' : (Number(raw) as never));
  }

  return (
    <Card className="space-y-4">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <SelectField
          label="Date range"
          options={RANGE_OPTIONS}
          value={draft.range}
          onChange={(event) => set('range', event.target.value as PortfolioRangeKey)}
        />

        <SelectField
          label="Loan product"
          options={[
            { value: '', label: 'All products' },
            ...(products.data ?? []).map((option) => ({ value: String(option.value), label: option.label })),
          ]}
          value={draft.loan_product_id ? String(draft.loan_product_id) : ''}
          onChange={(event) => numeric('loan_product_id', event.target.value)}
        />

        <SelectField
          label="Loan status"
          options={STATUS_OPTIONS}
          value={draft.status ?? ''}
          onChange={(event) => set('status', event.target.value as PortfolioFilterState['status'])}
        />

        <SelectField
          label="Loan officer"
          options={[
            { value: '', label: 'All officers' },
            ...(officers.data?.items ?? []).map((staff) => ({ value: String(staff.id), label: staff.full_name })),
          ]}
          value={draft.loan_officer_id ? String(draft.loan_officer_id) : ''}
          onChange={(event) => numeric('loan_officer_id', event.target.value)}
        />
      </div>

      {draft.range === 'custom' ? (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Field
            label="From"
            type="date"
            value={draft.date_from ?? ''}
            onChange={(event) => set('date_from', event.target.value)}
          />
          <Field
            label="To"
            type="date"
            value={draft.date_to ?? ''}
            onChange={(event) => set('date_to', event.target.value)}
          />
        </div>
      ) : null}

      {advancedOpen ? (
        <div className="grid grid-cols-1 gap-3 border-t border-slate-200/70 pt-4 sm:grid-cols-2 lg:grid-cols-4">
          <SelectField
            label="Repayment status"
            options={REPAYMENT_STATUS_OPTIONS}
            value={draft.repayment_status ?? ''}
            onChange={(event) => set('repayment_status', event.target.value as PortfolioFilterState['repayment_status'])}
          />
          <Field
            label="Min. days past due"
            type="number"
            min={0}
            placeholder="e.g. 30"
            value={draft.min_days_past_due?.toString() ?? ''}
            onChange={(event) => numeric('min_days_past_due', event.target.value)}
          />
          <SelectField
            label="Branch"
            options={[
              { value: '', label: 'All branches' },
              ...(branches.data ?? []).map((option) => ({ value: String(option.value), label: option.label })),
            ]}
            value={draft.branch_id ? String(draft.branch_id) : ''}
            onChange={(event) => numeric('branch_id', event.target.value)}
          />
          <Field
            label="Borrower ID"
            type="number"
            min={1}
            placeholder="Merchant #"
            value={draft.borrower_id?.toString() ?? ''}
            onChange={(event) => numeric('borrower_id', event.target.value)}
          />
          <Field
            label="Loan ID"
            type="number"
            min={1}
            value={draft.loan_id?.toString() ?? ''}
            onChange={(event) => numeric('loan_id', event.target.value)}
          />
          <Field
            label="Disbursement channel (bank account ID)"
            type="number"
            min={1}
            value={draft.disbursement_channel_id?.toString() ?? ''}
            onChange={(event) => numeric('disbursement_channel_id', event.target.value)}
          />
        </div>
      ) : null}

      <div className="flex flex-wrap items-center gap-3">
        <Button type="button" onClick={() => onApply(draft)} loading={isFetching} disabled={!dirty && !isFetching}>
          Apply filters
        </Button>
        <Button
          type="button"
          variant="secondary"
          onClick={() => onApply({ ...DEFAULT_PORTFOLIO_FILTERS })}
          disabled={activeCount === 0 && !dirty}
        >
          <RotateCcw className="size-4" aria-hidden />
          Clear
        </Button>
        <button
          type="button"
          onClick={() => setAdvancedOpen((open) => !open)}
          className="flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:underline"
        >
          <SlidersHorizontal className="size-4" aria-hidden />
          {advancedOpen ? 'Fewer filters' : 'More filters'}
        </button>

        <span className={cn('ml-auto text-xs', dirty ? 'text-warning' : 'text-slate-400')}>
          {dirty
            ? 'Unapplied changes'
            : activeCount > 0
              ? `${activeCount} filter${activeCount === 1 ? '' : 's'} active`
              : 'Showing the current portfolio'}
        </span>
      </div>
    </Card>
  );
}
