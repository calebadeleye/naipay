'use client';

import { ApiError } from '@naipay/api-client';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useState, type FormEvent } from 'react';

import { Alert, Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import {
  useEstablishSession,
  useLogin,
  useSendTwoFactorEmailCode,
  useTwoFactorChallenge,
} from '@/lib/auth/use-auth';
import { isTwoFactorChallenge, type SessionPayload } from '@/lib/auth/types';

export function SignInForm() {
  const searchParams = useSearchParams();
  const returnTo = searchParams.get('return_to');

  const login = useLogin();
  const challenge = useTwoFactorChallenge();
  const sendEmailCode = useSendTwoFactorEmailCode();
  const establishSession = useEstablishSession();

  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [emailCodeSent, setEmailCodeSent] = useState(false);

  // Holding the challenge token in component state rather than storage keeps
  // it out of anything that outlives the tab, and it is single-use anyway.
  const [challengeToken, setChallengeToken] = useState<string | null>(null);

  const error = (login.error ?? challenge.error) as ApiError | null;
  const pending = login.isPending || challenge.isPending;

  async function handleCredentials(event: FormEvent) {
    event.preventDefault();
    login.reset();

    const result = await login.mutateAsync({ identifier, password }).catch(() => null);

    if (result === null) {
      return;
    }

    if (isTwoFactorChallenge(result)) {
      setChallengeToken(result.challenge_token);
      setPassword('');

      return;
    }

    establishSession(result as SessionPayload, returnTo);
  }

  async function handleChallenge(event: FormEvent) {
    event.preventDefault();
    challenge.reset();

    if (challengeToken === null) {
      return;
    }

    const session = await challenge
      .mutateAsync({ challenge_token: challengeToken, code })
      .catch(() => null);

    if (session === null) {
      return;
    }

    establishSession(session, returnTo);
  }

  function restart() {
    setChallengeToken(null);
    setCode('');
    setEmailCodeSent(false);
    challenge.reset();
    login.reset();
    sendEmailCode.reset();
  }

  async function handleSendEmailCode() {
    if (challengeToken === null) {
      return;
    }

    try {
      await sendEmailCode.mutateAsync({ challenge_token: challengeToken });
      setEmailCodeSent(true);
    } catch {
      // Surfaced via `sendEmailCode.error` below, rendered from the mutation state.
    }
  }

  if (challengeToken !== null) {
    return (
      <form onSubmit={handleChallenge} className="space-y-4" noValidate>
        {error ? (
          <Alert tone="error" reference={error.correlationId}>
            {error.message}
          </Alert>
        ) : null}

        <Field
          label="Verification code"
          name="code"
          value={code}
          onChange={(event) => setCode(event.target.value)}
          autoComplete="one-time-code"
          inputMode="text"
          autoFocus
          required
          hint="Enter the six-digit code from your authenticator app, or one of your recovery codes."
          error={error?.fieldError('code')}
          className="numeric tracking-widest"
        />

        {sendEmailCode.error instanceof ApiError ? (
          <Alert tone="error" reference={sendEmailCode.error.correlationId}>
            {sendEmailCode.error.message}
          </Alert>
        ) : null}

        {emailCodeSent ? (
          <p className="text-sm text-slate-600">
            A code was emailed to you. Check your inbox (and spam), then enter it above.{' '}
            <button
              type="button"
              onClick={handleSendEmailCode}
              disabled={sendEmailCode.isPending}
              className="font-medium text-brand-700 hover:text-brand-800 disabled:opacity-60"
            >
              Send another
            </button>
          </p>
        ) : (
          <button
            type="button"
            onClick={handleSendEmailCode}
            disabled={sendEmailCode.isPending}
            className="w-full text-center text-sm text-brand-700 hover:text-brand-800 disabled:opacity-60"
          >
            {sendEmailCode.isPending ? 'Sending…' : "Don't have your authenticator app? Email me a code"}
          </button>
        )}

        <Button type="submit" fullWidth loading={pending}>
          Verify and sign in
        </Button>

        <button
          type="button"
          onClick={restart}
          className="w-full text-center text-sm text-slate-500 hover:text-slate-700"
        >
          Back to sign in
        </button>
      </form>
    );
  }

  return (
    <form onSubmit={handleCredentials} className="space-y-4" noValidate>
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}

      <Field
        label="Email or username"
        name="identifier"
        value={identifier}
        onChange={(event) => setIdentifier(event.target.value)}
        autoComplete="username"
        autoFocus
        required
        error={error?.fieldError('identifier')}
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

      <Button type="submit" fullWidth loading={pending}>
        Sign in
      </Button>

      <div className="text-center">
        <Link href="/forgot-password" className="text-sm text-brand-700 hover:text-brand-800">
          Forgotten your password?
        </Link>
      </div>
    </form>
  );
}
