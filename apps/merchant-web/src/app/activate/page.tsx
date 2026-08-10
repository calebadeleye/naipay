'use client';

import { ApiError } from '@naipay/api-client';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Suspense, useState, type FormEvent } from 'react';

import { AuthShell } from '@/components/auth/auth-shell';
import { PasswordRequirements } from '@/components/auth/password-requirements';
import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { useActivate } from '@/lib/auth/use-auth';

function ActivateForm() {
  const searchParams = useSearchParams();
  const email = searchParams.get('email') ?? '';
  const token = searchParams.get('token') ?? '';

  const activate = useActivate();

  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');

  const error = activate.error as ApiError | null;

  function handleSubmit(event: FormEvent) {
    event.preventDefault();

    activate.mutate({ email, token, password, password_confirmation: confirmation });
  }

  if (token === '' || email === '') {
    return <Alert tone="error">This activation link is incomplete. Request a new one.</Alert>;
  }

  if (activate.isSuccess) {
    return (
      <div className="space-y-4">
        <Alert tone="success">Your account has been activated.</Alert>
        <Link href="/sign-in">
          <Button fullWidth>Sign in</Button>
        </Link>
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4" noValidate>
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}

      <Field label="Email address" value={email} readOnly disabled />

      <Field
        label="Choose a password"
        name="password"
        type="password"
        value={password}
        onChange={(event) => setPassword(event.target.value)}
        autoComplete="new-password"
        autoFocus
        required
        error={error?.fieldError('password')}
      />

      <Field
        label="Confirm password"
        name="password_confirmation"
        type="password"
        value={confirmation}
        onChange={(event) => setConfirmation(event.target.value)}
        autoComplete="new-password"
        required
      />

      <PasswordRequirements password={password} />

      <Button type="submit" fullWidth loading={activate.isPending}>
        Activate account
      </Button>
    </form>
  );
}

export default function ActivatePage() {
  return (
    <AuthShell title="Activate your account" description="Set a password to finish setting up your portal access.">
      <Suspense fallback={null}>
        <ActivateForm />
      </Suspense>
    </AuthShell>
  );
}
