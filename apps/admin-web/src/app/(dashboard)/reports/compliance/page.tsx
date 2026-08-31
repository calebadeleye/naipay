'use client';

import { Card, StatCard } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { formatNumber } from '@/lib/format';
import { useComplianceOverview } from '@/lib/reports/use-reports';

export default function CompliancePage() {
  const { data, isLoading, error } = useComplianceOverview();

  return (
    <>
      <PageHeader
        title="Compliance overview"
        description="Merchants awaiting KYC and businesses awaiting verification, aged by days waiting."
      />

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <div className="space-y-6">
            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <StatCard
                label="Merchants awaiting KYC"
                value={formatNumber(data.pending_kyc.count)}
                tone={data.pending_kyc.count > 0 ? 'warning' : 'default'}
              />
              <StatCard
                label="Businesses awaiting verification"
                value={formatNumber(data.pending_business_verification.count)}
                tone={data.pending_business_verification.count > 0 ? 'warning' : 'default'}
              />
            </section>

            <Card className="overflow-x-auto p-0">
              <div className="border-b border-slate-200 px-4 py-3">
                <h2 className="text-sm font-semibold text-slate-900">Merchants awaiting KYC</h2>
              </div>
              {data.pending_kyc.merchants.length === 0 ? (
                <p className="px-4 py-6 text-sm text-slate-500">Nothing outstanding.</p>
              ) : (
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                      <th className="px-4 py-2">Merchant</th>
                      <th className="px-4 py-2">Merchant no.</th>
                      <th className="px-4 py-2">KYC status</th>
                      <th className="px-4 py-2 text-right">Days waiting</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.pending_kyc.merchants.map((merchant) => (
                      <tr key={merchant.id} className="border-t border-slate-100">
                        <td className="px-4 py-2.5 font-medium text-slate-900">
                          {merchant.full_name}
                        </td>
                        <td className="numeric px-4 py-2.5 text-slate-500">
                          {merchant.merchant_number}
                        </td>
                        <td className="px-4 py-2.5 text-slate-700 capitalize">
                          {merchant.kyc_status.replace('_', ' ')}
                        </td>
                        <td className="numeric px-4 py-2.5 text-right text-slate-700">
                          {merchant.days_waiting !== null ? formatNumber(merchant.days_waiting) : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </Card>

            <Card className="overflow-x-auto p-0">
              <div className="border-b border-slate-200 px-4 py-3">
                <h2 className="text-sm font-semibold text-slate-900">
                  Businesses awaiting verification
                </h2>
              </div>
              {data.pending_business_verification.businesses.length === 0 ? (
                <p className="px-4 py-6 text-sm text-slate-500">Nothing outstanding.</p>
              ) : (
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                      <th className="px-4 py-2">Business</th>
                      <th className="px-4 py-2 text-right">Days waiting</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.pending_business_verification.businesses.map((business) => (
                      <tr key={business.id} className="border-t border-slate-100">
                        <td className="px-4 py-2.5 font-medium text-slate-900">
                          {business.business_name}
                        </td>
                        <td className="numeric px-4 py-2.5 text-right text-slate-700">
                          {formatNumber(business.days_waiting)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </Card>
          </div>
        ) : null}
      </QueryState>
    </>
  );
}
