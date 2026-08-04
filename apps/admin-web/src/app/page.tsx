import { SystemStatus } from '@/components/system-status';
import { env } from '@/lib/env';

/**
 * Foundation landing screen.
 *
 * Phase 0 has no authenticated surface yet, so this page exists to confirm the
 * console is wired to the API correctly — the environment is valid, the client
 * reaches /api/v1/health, and the envelope decodes. The authenticated shell
 * and dashboard replace it in the administrative UI phase.
 */
export default function HomePage() {
  return (
    <main className="mx-auto flex min-h-screen w-full max-w-3xl flex-col justify-center gap-8 px-6 py-16">
      <header className="space-y-2">
        <p className="text-sm font-medium tracking-wide text-brand-600 uppercase">
          {env.NEXT_PUBLIC_ENVIRONMENT}
        </p>
        <h1 className="text-3xl font-semibold text-slate-900">Naipay Administration</h1>
        <p className="text-slate-600">
          Merchant microfinance administration platform. Authentication and the administrative
          console arrive in the next phases.
        </p>
      </header>

      <SystemStatus />

      <footer className="text-sm text-slate-500">
        API base URL <code className="numeric text-slate-700">{env.NEXT_PUBLIC_API_URL}</code>
      </footer>
    </main>
  );
}
