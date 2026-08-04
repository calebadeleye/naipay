'use client';

import { AlertCircle } from 'lucide-react';
import type { InputHTMLAttributes, ReactNode } from 'react';
import { useId } from 'react';

import { cn } from '@/lib/cn';

interface FieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  label: string;
  error?: string;
  hint?: string;
}

/**
 * A labelled text input with inline validation.
 *
 * The error is wired to the input via `aria-describedby` and `aria-invalid`
 * rather than shown as colour alone, so it reaches screen readers and remains
 * legible to colour-blind operators.
 */
export function Field({ label, error, hint, className, ...props }: FieldProps) {
  const id = useId();
  const errorId = `${id}-error`;
  const hintId = `${id}-hint`;

  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-slate-800">
        {label}
      </label>

      <input
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={cn(error && errorId, hint && hintId) || undefined}
        className={cn(
          'block w-full rounded-md border px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400',
          'focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none',
          error ? 'border-danger' : 'border-slate-300',
          className,
        )}
        {...props}
      />

      {hint && !error ? (
        <p id={hintId} className="text-xs text-slate-500">
          {hint}
        </p>
      ) : null}

      {error ? (
        <p id={errorId} className="flex items-start gap-1.5 text-xs text-danger">
          <AlertCircle className="mt-px size-3.5 shrink-0" aria-hidden />
          {error}
        </p>
      ) : null}
    </div>
  );
}

interface AlertProps {
  tone: 'error' | 'success' | 'info' | 'warning';
  children: ReactNode;
  /** Shown in small print — the correlation ID an operator quotes to support. */
  reference?: string | null;
}

export function Alert({ tone, children, reference }: AlertProps) {
  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className={cn(
        'rounded-md border px-3 py-2.5 text-sm',
        tone === 'error' && 'border-red-200 bg-danger-surface text-red-900',
        tone === 'success' && 'border-green-200 bg-success-surface text-green-900',
        tone === 'info' && 'border-blue-200 bg-info-surface text-blue-900',
        tone === 'warning' && 'border-amber-200 bg-warning-surface text-amber-900',
      )}
    >
      {children}
      {reference ? (
        <p className="numeric mt-1 text-xs opacity-75">Reference {reference}</p>
      ) : null}
    </div>
  );
}
