'use client';

import { Check, Copy, Printer } from 'lucide-react';
import { useState } from 'react';

import { Alert } from '@/components/ui/field';
import { Button } from '@/components/ui/button';

/**
 * Displays recovery codes, once.
 *
 * The operator has to acknowledge saving them before continuing. Recovery
 * codes are the only way back into an account whose authenticator is lost, and
 * the server holds only hashes — nobody can reissue these specific values.
 */
export function RecoveryCodes({
  codes,
  onContinue,
}: {
  codes: string[];
  onContinue: () => void;
}) {
  const [copied, setCopied] = useState(false);
  const [acknowledged, setAcknowledged] = useState(false);

  async function copy() {
    await navigator.clipboard.writeText(codes.join('\n'));
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  return (
    <div className="space-y-4">
      <Alert tone="warning">
        Store these somewhere safe and offline. Each code works once, and they cannot be shown
        again.
      </Alert>

      <ul className="grid grid-cols-2 gap-2 rounded-md border border-slate-200 bg-slate-50 p-3">
        {codes.map((code) => (
          <li key={code} className="numeric text-sm text-slate-800">
            {code}
          </li>
        ))}
      </ul>

      <div className="flex gap-2">
        <Button type="button" variant="secondary" size="sm" onClick={copy}>
          {copied ? <Check className="size-4" aria-hidden /> : <Copy className="size-4" aria-hidden />}
          {copied ? 'Copied' : 'Copy codes'}
        </Button>

        <Button type="button" variant="secondary" size="sm" onClick={() => window.print()}>
          <Printer className="size-4" aria-hidden />
          Print
        </Button>
      </div>

      <label className="flex items-start gap-2 text-sm text-slate-700">
        <input
          type="checkbox"
          checked={acknowledged}
          onChange={(event) => setAcknowledged(event.target.checked)}
          className="mt-0.5 size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
        />
        I have saved my recovery codes.
      </label>

      <Button type="button" fullWidth disabled={!acknowledged} onClick={onContinue}>
        Continue to Every Merchant
      </Button>
    </div>
  );
}
