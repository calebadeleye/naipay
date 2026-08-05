'use client';

import { ApiError } from '@naipay/api-client';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { SelectField } from '@/components/ui/select';
import { TextareaField } from '@/components/ui/textarea';
import { ActionButton } from '@/components/ui/workflow-action';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import {
  useAddStatementLine,
  useApproveReconciliation,
  useExcludeLine,
  useMatchLine,
  useMatchSuggestions,
  useReconciliation,
  useSubmitReconciliation,
  useUnmatchLine,
} from '@/lib/reconciliation/use-reconciliation';
import type {
  BankReconciliationStatusKey,
  BankStatementLine,
  StatementLineInput,
} from '@/lib/reconciliation/types';

const statusTone: Record<BankReconciliationStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  in_progress: 'neutral',
  pending_approval: 'warning',
  approved: 'success',
};

const lineStatusTone = {
  unmatched: 'neutral',
  matched: 'success',
  excluded: 'warning',
} as const;

const directionOptions = [
  { value: 'credit', label: 'Credit (money in)' },
  { value: 'debit', label: 'Debit (money out)' },
];

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

const emptyLine: StatementLineInput = {
  statement_date: '',
  description: '',
  external_reference: '',
  amount: '',
  direction: 'credit',
};

function AddLineForm({ reconciliationId, onDone }: { reconciliationId: number; onDone: () => void }) {
  const [form, setForm] = useState<StatementLineInput>(emptyLine);
  const addLine = useAddStatementLine(reconciliationId);
  const error = addLine.error instanceof ApiError ? addLine.error : null;

  function set<K extends keyof StatementLineInput>(key: K, value: StatementLineInput[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();

    try {
      await addLine.mutateAsync(form);
      onDone();
    } catch {
      // Surfaced via `error` above, rendered from the mutation state.
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4 rounded-md border border-slate-200 bg-slate-50 p-4">
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field
          label="Statement date"
          type="date"
          required
          value={form.statement_date}
          onChange={(event) => set('statement_date', event.target.value)}
          error={error?.fieldError('statement_date')}
        />
        <SelectField
          label="Direction"
          options={directionOptions}
          value={form.direction}
          onChange={(event) => set('direction', event.target.value)}
          error={error?.fieldError('direction')}
        />
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field
          label="Amount"
          required
          placeholder="0.00"
          value={form.amount}
          onChange={(event) => set('amount', event.target.value)}
          error={error?.fieldError('amount')}
        />
        <Field
          label="External reference"
          value={form.external_reference}
          onChange={(event) => set('external_reference', event.target.value)}
          error={error?.fieldError('external_reference')}
        />
      </div>

      <Field
        label="Description"
        value={form.description}
        onChange={(event) => set('description', event.target.value)}
        error={error?.fieldError('description')}
      />

      <div className="flex gap-3">
        <Button type="submit" loading={addLine.isPending}>
          Add line
        </Button>
        <Button type="button" variant="secondary" onClick={onDone}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

function MatchLineForm({
  reconciliationId,
  line,
  onDone,
}: {
  reconciliationId: number;
  line: BankStatementLine;
  onDone: () => void;
}) {
  const [type, setType] = useState<'repayment' | 'loan'>('repayment');
  const [manualId, setManualId] = useState('');
  const { data: suggestions, isFetching } = useMatchSuggestions(reconciliationId, line.id);
  const matchLine = useMatchLine(reconciliationId);
  const error = matchLine.error instanceof ApiError ? matchLine.error : null;

  async function confirmMatch(id: number) {
    try {
      await matchLine.mutateAsync({ lineId: line.id, matched_to_type: type, matched_to_id: id });
      onDone();
    } catch {
      // Surfaced via `error` below, rendered from the mutation state.
    }
  }

  return (
    <div className="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-3">
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}

      <SelectField
        label="Match against"
        options={[
          { value: 'repayment', label: 'Repayment' },
          { value: 'loan', label: 'Loan' },
        ]}
        value={type}
        onChange={(event) => setType(event.target.value as 'repayment' | 'loan')}
      />

      {isFetching ? (
        <p className="text-sm text-slate-500">Loading candidates…</p>
      ) : suggestions && suggestions.length > 0 ? (
        <div className="space-y-1.5">
          <p className="text-xs font-medium text-slate-600">Suggested matches</p>
          {suggestions.map((suggestion) => (
            <button
              key={suggestion.id}
              type="button"
              onClick={() => confirmMatch(suggestion.id)}
              className="numeric block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-left text-sm hover:bg-slate-50"
            >
              {suggestion.reference}
            </button>
          ))}
        </div>
      ) : (
        <p className="text-sm text-slate-500">No suggested candidates for this line.</p>
      )}

      <div className="flex items-end gap-2">
        <Field
          label={`${type === 'repayment' ? 'Repayment' : 'Loan'} ID`}
          value={manualId}
          onChange={(event) => setManualId(event.target.value)}
          className="w-32"
        />
        <Button
          type="button"
          loading={matchLine.isPending}
          disabled={!manualId}
          onClick={() => confirmMatch(Number(manualId))}
        >
          Match
        </Button>
        <Button type="button" variant="secondary" onClick={onDone}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

function ExcludeLineForm({
  reconciliationId,
  lineId,
  onDone,
}: {
  reconciliationId: number;
  lineId: number;
  onDone: () => void;
}) {
  const [reason, setReason] = useState('');
  const excludeLine = useExcludeLine(reconciliationId);
  const error = excludeLine.error instanceof ApiError ? excludeLine.error : null;

  async function handleConfirm() {
    try {
      await excludeLine.mutateAsync({ lineId, reason });
      onDone();
    } catch {
      // Surfaced via `error` below, rendered from the mutation state.
    }
  }

  return (
    <div className="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-3">
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}
      <TextareaField label="Reason for exclusion" value={reason} onChange={(event) => setReason(event.target.value)} autoFocus />
      <div className="flex gap-2">
        <Button type="button" variant="destructive" loading={excludeLine.isPending} onClick={handleConfirm}>
          Confirm exclusion
        </Button>
        <Button type="button" variant="secondary" onClick={onDone}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

function StatementLineRow({ reconciliationId, line }: { reconciliationId: number; line: BankStatementLine }) {
  const [action, setAction] = useState<'match' | 'exclude' | null>(null);
  const unmatchLine = useUnmatchLine(reconciliationId);

  return (
    <>
      <tr className="border-b border-slate-100 last:border-0">
        <td className="px-4 py-3 text-slate-700">{line.statement_date}</td>
        <td className="px-4 py-3 text-slate-700">{line.description ?? '—'}</td>
        <td className="numeric px-4 py-3 text-right text-slate-700">
          {line.direction === 'debit' ? '-' : ''}
          {formatMoney(line.amount)}
        </td>
        <td className="px-4 py-3">
          <Badge tone={lineStatusTone[line.status]}>{line.status_label}</Badge>
        </td>
        <td className="px-4 py-3 text-slate-700">
          {line.matched_to ? (
            <Link
              href={line.matched_to.type === 'repayment' ? `/repayments/${line.matched_to.id}` : `/loans/${line.matched_to.id}`}
              className="text-brand-700 hover:underline"
            >
              {line.matched_to.reference}
            </Link>
          ) : line.excluded_reason ? (
            <span className="text-slate-500">{line.excluded_reason}</span>
          ) : (
            '—'
          )}
        </td>
        <td className="px-4 py-3 text-right">
          {line.status === 'unmatched' ? (
            <div className="flex justify-end gap-2">
              <Button type="button" size="sm" variant="secondary" onClick={() => setAction('match')}>
                Match
              </Button>
              <Button type="button" size="sm" variant="ghost" onClick={() => setAction('exclude')}>
                Exclude
              </Button>
            </div>
          ) : line.status === 'matched' ? (
            <ActionButton
              label="Unmatch"
              variant="secondary"
              onConfirm={() => unmatchLine.mutateAsync(line.id)}
            />
          ) : null}
        </td>
      </tr>
      {action ? (
        <tr>
          <td colSpan={6} className="px-4 py-3">
            {action === 'match' ? (
              <MatchLineForm reconciliationId={reconciliationId} line={line} onDone={() => setAction(null)} />
            ) : (
              <ExcludeLineForm reconciliationId={reconciliationId} lineId={line.id} onDone={() => setAction(null)} />
            )}
          </td>
        </tr>
      ) : null}
    </>
  );
}

export default function ReconciliationDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: reconciliation, isLoading, error } = useReconciliation(id);
  const [addingLine, setAddingLine] = useState(false);

  const submit = useSubmitReconciliation(id);
  const approve = useApproveReconciliation(id);

  return (
    <QueryState isLoading={isLoading} error={error}>
      {reconciliation ? (
        <div className="space-y-6">
          <PageHeader
            title={reconciliation.bank_account ?? 'Reconciliation'}
            description={`${formatDate(reconciliation.period_start)} – ${formatDate(reconciliation.period_end)}`}
            actions={<Badge tone={statusTone[reconciliation.status]}>{reconciliation.status_label}</Badge>}
          />

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Workflow</h2>
            <div className="flex flex-wrap items-start gap-3">
              {reconciliation.allowed_transitions.includes('pending_approval') ? (
                <ActionButton label="Submit for approval" onConfirm={() => submit.mutateAsync()} />
              ) : null}
              {reconciliation.allowed_transitions.includes('approved') ? (
                <ActionButton label="Approve" onConfirm={() => approve.mutateAsync()} />
              ) : null}
              {reconciliation.allowed_transitions.length === 0 ? (
                <p className="text-sm text-slate-500">No further transitions — this reconciliation is approved.</p>
              ) : null}
            </div>
            {reconciliation.has_unresolved_lines ? (
              <p className="mt-3 text-sm text-warning">
                Every line must be matched or excluded before this can be submitted.
              </p>
            ) : null}
          </Card>

          <Card className="max-w-2xl">
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Statement</h2>
            <div className="grid grid-cols-2 gap-4">
              <Detail label="Opening balance" value={formatMoney(reconciliation.statement_opening_balance)} />
              <Detail label="Closing balance" value={formatMoney(reconciliation.statement_closing_balance)} />
              <Detail label="Prepared by" value={reconciliation.prepared_by?.full_name} />
              <Detail label="Approved by" value={reconciliation.approved_by?.full_name} />
            </div>
            {reconciliation.notes ? (
              <>
                <h3 className="mt-4 text-xs font-medium tracking-wide text-slate-500 uppercase">Notes</h3>
                <p className="mt-1 text-sm text-slate-700">{reconciliation.notes}</p>
              </>
            ) : null}
          </Card>

          <Card className="overflow-x-auto p-0">
            <div className="flex items-center justify-between px-4 pt-4">
              <h2 className="text-sm font-semibold text-slate-900">Statement lines</h2>
              {reconciliation.status === 'in_progress' && !addingLine ? (
                <Button type="button" variant="secondary" size="sm" onClick={() => setAddingLine(true)}>
                  Add line
                </Button>
              ) : null}
            </div>

            {addingLine ? (
              <div className="p-4">
                <AddLineForm reconciliationId={reconciliation.id} onDone={() => setAddingLine(false)} />
              </div>
            ) : null}

            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-4 py-3">Date</th>
                  <th className="px-4 py-3">Description</th>
                  <th className="px-4 py-3 text-right">Amount</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Matched to / reason</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody>
                {reconciliation.lines.map((line) => (
                  <StatementLineRow key={line.id} reconciliationId={reconciliation.id} line={line} />
                ))}
                {reconciliation.lines.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                      No statement lines added yet.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </Card>

          <p className="text-xs text-slate-400">Last updated {formatDateTime(reconciliation.updated_at)}</p>
        </div>
      ) : null}
    </QueryState>
  );
}
