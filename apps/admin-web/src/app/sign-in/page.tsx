import { Suspense } from 'react';

import { SignInForm } from '@/components/auth/sign-in-form';
import { AuthShell } from '@/components/auth/auth-shell';

export const metadata = {
  title: 'Sign in',
};

export default function SignInPage() {
  return (
    <AuthShell
      title="Sign in"
      description="Use your Every Merchant staff credentials."
    >
      {/* useSearchParams needs a suspense boundary during prerender. */}
      <Suspense fallback={null}>
        <SignInForm />
      </Suspense>
    </AuthShell>
  );
}
