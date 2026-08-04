'use client';

import { ApiError } from '@naipay/api-client';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';

import { AuthShell } from '@/components/auth/auth-shell';
import { PasswordRequirements } from '@/components/auth/password-requirements';
import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { useChangePassword, useCurrentStaff } from '@/lib/auth/use-auth';

/**
 * Where an operator lands when their account carries `must_change_password` —
 * a newly issued account, or one an administrator has reset.
 *
 * Also reachable voluntarily from account settings.
 */
export default function ChangePasswordPage() {
  const router = useRouter();
  const { data: staff } = useCurrentStaff();
  const changePassword = useChangePassword();

  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');

  const error = changePassword.error as ApiError | null;
  const forced = staff?.must_change_password === true;

  function handleSubmit(event: FormEvent) {
    event.preventDefault();

    changePassword.mutate(
      {
        current_password: currentPassword,
        password,
        password_confirmation: confirmation,
      },
      {
        onSuccess: () => {
          // The account may still owe two-factor enrolment, so the next
          // destination is decided by the server on the following request
          // rather than assumed here.
          router.replace(staff?.two_factor.required && !staff.two_factor.enabled
            ? '/two-factor/enrol'
            : '/');
        },
      },
    );
  }

  return (
    <AuthShell
      title={forced ? 'Change your password to continue' : 'Change your password'}
      description={
        forced
          ? 'This account was issued with a temporary password. Choose your own before continuing.'
          : undefined
      }
    >
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        {error ? (
          <Alert tone="error" reference={error.correlationId}>
            {error.message}
          </Alert>
        ) : null}

        <Alert tone="info">
          Changing your password signs out every other session. You will stay signed in here.
        </Alert>

        <Field
          label="Current password"
          name="current_password"
          type="password"
          value={currentPassword}
          onChange={(event) => setCurrentPassword(event.target.value)}
          autoComplete="current-password"
          autoFocus
          required
          error={error?.fieldError('current_password')}
        />

        <Field
          label="New password"
          name="password"
          type="password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          autoComplete="new-password"
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

        <Button type="submit" fullWidth loading={changePassword.isPending}>
          Change password
        </Button>
      </form>
    </AuthShell>
  );
}
