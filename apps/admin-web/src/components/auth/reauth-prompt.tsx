'use client';

import { useState } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import type { ApiError } from '@naipay/api-client';

interface ReauthPromptProps {
  onConfirm: (password: string, code?: string) => Promise<unknown>;
  onCancel: () => void;
  pending: boolean;
  error: ApiError | null;
  /**
   * Set for an operation in
   * naipay.security.reauthentication_two_factor_optional_operations (e.g.
   * granting a role) — the server accepts a password alone there, so the
   * two-factor field is left out rather than shown as if optional. Leave
   * unset for anything else; the server still requires a two-factor code
   * when the account has one enabled.
   */
  twoFactorOptional?: boolean;
}

/**
 * The step-up prompt shown when the API refuses a sensitive action with a
 * 428, asking the operator to prove it is still them before continuing.
 */
export function ReauthPrompt({ onConfirm, onCancel, pending, error, twoFactorOptional }: ReauthPromptProps) {
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    await onConfirm(password, code || undefined);
  }

  return (
    <form
      onSubmit={handleSubmit}
      className="w-full max-w-md space-y-3 rounded-md border border-amber-200 bg-warning-surface p-4"
    >
      <p className="text-sm font-medium text-slate-900">Confirm it is still you</p>
      <p className="text-sm text-slate-600">
        {twoFactorOptional
          ? 'This action requires your password again before continuing.'
          : 'This action requires your password, and your two-factor code if enabled, before continuing.'}
      </p>

      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}

      <Field
        label="Password"
        type="password"
        required
        autoFocus
        value={password}
        onChange={(event) => setPassword(event.target.value)}
      />
      {twoFactorOptional ? null : (
        <Field
          label="Two-factor code"
          placeholder="Leave blank if not enabled"
          value={code}
          onChange={(event) => setCode(event.target.value)}
        />
      )}

      <div className="flex gap-2">
        <Button type="submit" loading={pending}>
          Confirm
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
