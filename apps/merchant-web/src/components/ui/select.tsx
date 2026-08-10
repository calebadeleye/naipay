'use client';

import type { SelectHTMLAttributes } from 'react';
import { useId } from 'react';

import { cn } from '@/lib/cn';

interface SelectFieldProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'id'> {
  label: string;
  error?: string;
  hint?: string;
  options: { value: string; label: string }[];
  placeholder?: string;
}

export function SelectField({
  label,
  error,
  hint,
  options,
  placeholder,
  className,
  ...props
}: SelectFieldProps) {
  const id = useId();
  const errorId = `${id}-error`;
  const hintId = `${id}-hint`;

  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-slate-800">
        {label}
      </label>

      <select
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={cn(error && errorId, hint && hintId) || undefined}
        className={cn(
          'block w-full rounded-md border bg-white px-3 py-2 text-sm text-slate-900',
          'focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none',
          error ? 'border-danger' : 'border-slate-300',
          className,
        )}
        {...props}
      >
        {placeholder ? (
          <option value="" disabled>
            {placeholder}
          </option>
        ) : null}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>

      {hint && !error ? (
        <p id={hintId} className="text-xs text-slate-500">
          {hint}
        </p>
      ) : null}

      {error ? <p id={errorId} className="text-xs text-danger">{error}</p> : null}
    </div>
  );
}
