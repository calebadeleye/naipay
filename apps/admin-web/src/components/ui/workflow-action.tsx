'use client';

import { ApiError } from '@naipay/api-client';
import { useState } from 'react';

import { Alert } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { TextareaField } from '@/components/ui/textarea';

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'destructive';

interface ActionButtonProps {
  label: string;
  variant?: ButtonVariant;
  onConfirm: () => Promise<unknown>;
  /** Shown once the action has succeeded, in place of the button. */
  disabled?: boolean;
}

/**
 * A workflow action with no extra input — approve, verify, reinstate. Calls
 * straight through on click; the surrounding mutation is what actually knows
 * how to reach the API.
 */
export function ActionButton({ label, variant = 'primary', onConfirm, disabled }: ActionButtonProps) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  async function handleClick() {
    setPending(true);
    setError(null);

    try {
      await onConfirm();
    } catch (cause) {
      setError(cause instanceof ApiError ? cause : null);
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="inline-flex flex-col items-start gap-2">
      <Button type="button" variant={variant} loading={pending} disabled={disabled} onClick={handleClick}>
        {label}
      </Button>
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}
    </div>
  );
}

interface ReasonActionButtonProps {
  label: string;
  variant?: ButtonVariant;
  onConfirm: (reason: string) => Promise<unknown>;
  reasonLabel?: string;
  disabled?: boolean;
}

/**
 * A workflow action that requires a recorded reason — rejection, suspension,
 * reversal, a status change. Expands into an inline form rather than a modal:
 * every one of these actions belongs on a page an operator arrived at
 * deliberately, not a confirmation interrupting a list.
 */
export function ReasonActionButton({
  label,
  variant = 'destructive',
  onConfirm,
  reasonLabel = 'Reason',
  disabled,
}: ReasonActionButtonProps) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  if (!open) {
    return (
      <Button type="button" variant={variant} disabled={disabled} onClick={() => setOpen(true)}>
        {label}
      </Button>
    );
  }

  async function handleConfirm() {
    setPending(true);
    setError(null);

    try {
      await onConfirm(reason);
      setOpen(false);
      setReason('');
    } catch (cause) {
      setError(cause instanceof ApiError ? cause : null);
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="w-full max-w-md space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4">
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}

      <TextareaField
        label={reasonLabel}
        value={reason}
        onChange={(event) => setReason(event.target.value)}
        error={error?.fieldError('reason')}
        autoFocus
        hint="At least 10 characters. Recorded in the audit log."
      />

      <div className="flex gap-2">
        <Button type="button" variant={variant} loading={pending} onClick={handleConfirm}>
          Confirm
        </Button>
        <Button
          type="button"
          variant="secondary"
          onClick={() => {
            setOpen(false);
            setReason('');
            setError(null);
          }}
        >
          Cancel
        </Button>
      </div>
    </div>
  );
}
