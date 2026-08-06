'use client';

import { ApiError } from '@naipay/api-client';
import Link from 'next/link';
import { useState, type FormEvent } from 'react';

import { AuthShell } from '@/components/auth/auth-shell';
import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { useForgotPassword } from '@/lib/auth/use-auth';

export default function ForgotPasswordPage() {
  const forgotPassword = useForgotPassword();
  const [email, setEmail] = useState('');

  const error = forgotPassword.error as ApiError | null;

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    forgotPassword.mutate({ email });
  }

  return (
    <AuthShell
      title="Reset your password"
      description="We will email you a link to set a new password."
      footer={
        <Link href="/sign-in" className="text-brand-700 hover:text-brand-800">
          Back to sign in
        </Link>
      }
    >
      {forgotPassword.isSuccess ? (
        // The API deliberately reports the same thing whether or not the
        // address matched an account, and so does this screen — anything else
        // would make it an account-enumeration oracle.
        <Alert tone="success">
          If that email address matches an Every Merchant account, a reset link has been sent to it. The
          link expires in one hour.
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

          <Button type="submit" fullWidth loading={forgotPassword.isPending}>
            Send reset link
          </Button>
        </form>
      )}
    </AuthShell>
  );
}
