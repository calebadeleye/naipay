'use client';

import { ApiError } from '@naipay/api-client';
import { useState, type FormEvent } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { useEstablishSession, useLogin } from '@/lib/auth/use-auth';

export function SignInForm() {
  const login = useLogin();
  const establishSession = useEstablishSession();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  const error = login.error as ApiError | null;

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    login.reset();

    const session = await login.mutateAsync({ email, password }).catch(() => null);

    if (session === null) {
      return;
    }

    establishSession(session);
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4" noValidate>
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}

      <Field
        label="Email"
        name="email"
        type="email"
        value={email}
        onChange={(event) => setEmail(event.target.value)}
        autoComplete="username"
        autoFocus
        required
        error={error?.fieldError('email')}
      />

      <Field
        label="Password"
        name="password"
        type="password"
        value={password}
        onChange={(event) => setPassword(event.target.value)}
        autoComplete="current-password"
        required
        error={error?.fieldError('password')}
      />

      <Button type="submit" fullWidth loading={login.isPending}>
        Sign in
      </Button>
    </form>
  );
}
