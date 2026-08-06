import { InvestorAuthShell } from '@/components/investor/investor-auth-shell';
import { InvestorSignInForm } from '@/components/investor/investor-sign-in-form';

export default function InvestorSignInPage() {
  return (
    <InvestorAuthShell
      title="Investor sign in"
      description="View how your investment in Every Merchant is performing."
    >
      <InvestorSignInForm />
    </InvestorAuthShell>
  );
}
