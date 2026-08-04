'use client';

import { Check, X } from 'lucide-react';

import { cn } from '@/lib/cn';

/**
 * Live checklist of the staff password policy.
 *
 * Mirrors the API's rule exactly. Showing the requirements as they are met
 * beats letting an operator submit and be told what was wrong afterwards —
 * these accounts hold access to merchant identity data and the ledger, so the
 * bar is higher than a consumer application's and worth explaining.
 */
const RULES = [
  { label: 'At least 12 characters', test: (value: string) => value.length >= 12 },
  { label: 'An uppercase letter', test: (value: string) => /[A-Z]/.test(value) },
  { label: 'A lowercase letter', test: (value: string) => /[a-z]/.test(value) },
  { label: 'A number', test: (value: string) => /\d/.test(value) },
  { label: 'A symbol', test: (value: string) => /[^A-Za-z0-9]/.test(value) },
] as const;

export function PasswordRequirements({ password }: { password: string }) {
  return (
    <ul className="space-y-1 rounded-md bg-slate-50 p-3">
      {RULES.map((rule) => {
        const met = rule.test(password);

        return (
          <li key={rule.label} className="flex items-center gap-2 text-xs">
            {met ? (
              <Check className="size-3.5 shrink-0 text-success" aria-hidden />
            ) : (
              <X className="size-3.5 shrink-0 text-slate-400" aria-hidden />
            )}
            {/* Colour is never the only signal — the icon carries it too. */}
            <span className={cn(met ? 'text-slate-700' : 'text-slate-500')}>{rule.label}</span>
            <span className="sr-only">{met ? '(met)' : '(not yet met)'}</span>
          </li>
        );
      })}
    </ul>
  );
}
