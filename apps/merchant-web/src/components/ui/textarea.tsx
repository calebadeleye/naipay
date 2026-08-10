'use client';

import { AlertCircle } from 'lucide-react';
import type { TextareaHTMLAttributes } from 'react';
import { useId } from 'react';

import { cn } from '@/lib/cn';

interface TextareaFieldProps extends Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'id'> {
  label: string;
  error?: string;
  hint?: string;
}

export function TextareaField({ label, error, hint, className, ...props }: TextareaFieldProps) {
  const id = useId();
  const errorId = `${id}-error`;
  const hintId = `${id}-hint`;

  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-slate-800">
        {label}
      </label>

      <textarea
        id={id}
        rows={3}
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
