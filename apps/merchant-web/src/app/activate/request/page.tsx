'use client';

import { ApiError } from '@naipay/api-client';
import Link from 'next/link';
import { useState, type FormEvent } from 'react';

import { AuthShell } from '@/components/auth/auth-shell';
import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { useRequestActivation } from '@/lib/auth/use-auth';

export default function RequestActivationPage() {
  const requestActivation = useRequestActivation();
  const [email, setEmail] = useState('');

  const error = requestActivation.error as ApiError | null;

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    requestActivation.mutate({ email });
  }

  return (
    <AuthShell
      title="Activate your account"
      description="Enter the email address Every Merchant has on file and we'll send you a link to set your password."
      footer={
        <Link href="/sign-in" className="text-brand-700 hover:text-brand-800">
          Back to sign in
        </Link>
      }
    >
      {requestActivation.isSuccess ? (
        // Reported the same whether or not the address matched an approved,
        // not-yet-activated account — anything else would make this an
        // account-enumeration oracle.
        <Alert tone="success">
          If that email address matches an Every Merchant account ready to activate, a link has been sent
          to it. The link expires in one hour.
        </Alert>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          {error ? (
            <Alert tone="error" reference={error.correlationId}>
              {error.message}
            </Alert>
          ) : null}

          <Field
            label="Email address"
            name="email"
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            autoComplete="email"
            autoFocus
            required
            error={error?.fieldError('email')}
          />

          <Button type="submit" fullWidth loading={requestActivation.isPending}>
            Send activation link
          </Button>
        </form>
      )}
    </AuthShell>
  );
}
