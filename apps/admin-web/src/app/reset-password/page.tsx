'use client';

import { ApiError } from '@naipay/api-client';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Suspense, useState, type FormEvent } from 'react';

import { AuthShell } from '@/components/auth/auth-shell';
import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { useResetPassword } from '@/lib/auth/use-auth';
import { PasswordRequirements } from '@/components/auth/password-requirements';

function ResetPasswordForm() {
  const searchParams = useSearchParams();
  const email = searchParams.get('email') ?? '';
  const token = searchParams.get('token') ?? '';

  const resetPassword = useResetPassword();

  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');

  const error = resetPassword.error as ApiError | null;

  function handleSubmit(event: FormEvent) {
    event.preventDefault();

    resetPassword.mutate({
      email,
      token,
      password,
      password_confirmation: confirmation,
    });
  }

  if (token === '' || email === '') {
    return (
      <Alert tone="error">
        This reset link is incomplete. Request a new one from the sign-in screen.
      </Alert>
    );
  }

  if (resetPassword.isSuccess) {
    return (
      <div className="space-y-4">
        <Alert tone="success">
          Your password has been reset. All previous sessions have been signed out.
        </Alert>
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
        label="New password"
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
        label="Confirm new password"
        name="password_confirmation"
        type="password"
        value={confirmation}
        onChange={(event) => setConfirmation(event.target.value)}
        autoComplete="new-password"
        required
      />

      <PasswordRequirements password={password} />

      <Button type="submit" fullWidth loading={resetPassword.isPending}>
        Set new password
      </Button>
    </form>
  );
}

export default function ResetPasswordPage() {
  return (
    <AuthShell title="Set a new password">
      <Suspense fallback={null}>
        <ResetPasswordForm />
      </Suspense>
    </AuthShell>
  );
}
