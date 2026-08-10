import Link from 'next/link';

import { AuthShell } from '@/components/auth/auth-shell';
import { SignInForm } from '@/components/auth/sign-in-form';

export default function SignInPage() {
  return (
    <AuthShell
      title="Sign in"
      description="View your loans, apply for credit, and manage your Every Merchant account."
      footer={
        <div className="space-y-2">
          <p>
            <Link href="/forgot-password" className="text-brand-700 hover:text-brand-800">
              Forgot your password?
            </Link>
          </p>
          <p>
            New here?{' '}
            <Link href="/activate/request" className="text-brand-700 hover:text-brand-800">
              Activate your account
            </Link>
          </p>
        </div>
      }
    >
      <SignInForm />
    </AuthShell>
  );
}
