'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';

import { Alert } from '@/components/ui/field';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { useHasPermission } from '@/lib/auth/use-permission';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { ReasonActionButton } from '@/components/ui/workflow-action';
import { ReauthPrompt } from '@/components/auth/reauth-prompt';
import { formatDateTime } from '@/lib/format';
import { useProtectedAction } from '@/lib/auth/use-reauthenticate';
import {
  useApproveBankAccount,
  useBankAccount,
  useChangeBankAccountStatus,
  useSetDefaultBankAccount,
} from '@/lib/bank-accounts/use-bank-accounts';
import type { BankAccountStatusKey } from '@/lib/bank-accounts/types';

const statusTone: Record<BankAccountStatusKey, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  active: 'success',
  suspended: 'warning',
  closed: 'danger',
};

const statusTransitions: Record<BankAccountStatusKey, BankAccountStatusKey[]> = {
  active: ['suspended', 'closed'],
  suspended: ['active', 'closed'],
  closed: [],
};

const statusLabels: Record<BankAccountStatusKey, string> = {
  active: 'Active',
  suspended: 'Suspended',
  closed: 'Closed',
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className="mt-0.5 text-sm text-slate-900">{value ?? '—'}</p>
    </div>
  );
}

function ApproveAction({ accountId }: { accountId: number }) {
  const approveAccount = useApproveBankAccount(accountId);
  const protectedApprove = useProtectedAction(() => approveAccount.mutateAsync());

  if (protectedApprove.needsReauthentication) {
    return (
      <ReauthPrompt
        pending={protectedApprove.reauthenticating}
        error={protectedApprove.reauthenticationError}
        onCancel={protectedApprove.cancelReauthentication}
        onConfirm={(password, code) => protectedApprove.confirmReauthentication(password, code)}
      />
    );
  }

  return (
    <div className="inline-flex flex-col items-start gap-2">
      <Button type="button" loading={protectedApprove.running} onClick={() => protectedApprove.run(undefined)}>
        Approve
      </Button>
      {protectedApprove.actionError ? (
        <Alert tone="error" reference={protectedApprove.actionError.correlationId}>
          {protectedApprove.actionError.message}
        </Alert>
      ) : null}
    </div>
  );
}

export default function BankAccountDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: account, isLoading, error } = useBankAccount(id);
  const setDefault = useSetDefaultBankAccount(id);
  const changeStatus = useChangeBankAccountStatus(id);
  const canManage = useHasPermission('bank_accounts.manage');

  return (
    <QueryState isLoading={isLoading} error={error}>
      {account ? (
        <div className="space-y-6">
          <PageHeader
            title={account.bank_name}
            description={`${account.account_number_formatted} · ${account.account_name}`}
            actions={
              <div className="flex items-center gap-2">
                {!account.is_approved ? <Badge tone="warning">Pending approval</Badge> : null}
                <Badge tone={statusTone[account.status]}>{account.status_label}</Badge>
                {canManage ? (
                  <Link href={`/bank-accounts/${account.id}/edit`} className={buttonVariants({ variant: 'secondary', size: 'sm' })}>
                    Edit
                  </Link>
                ) : null}
              </div>
            }
          />

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Workflow</h2>
            <div className="flex flex-wrap items-start gap-3">
              {!account.is_approved ? <ApproveAction accountId={account.id} /> : null}

              {account.is_approved && !account.is_default_collection_account ? (
                <Button type="button" variant="secondary" onClick={() => setDefault.mutate('collection')}>
                  Set as default collection account
                </Button>
              ) : null}

              {account.is_approved && !account.is_default_disbursement_account ? (
                <Button type="button" variant="secondary" onClick={() => setDefault.mutate('disbursement')}>
                  Set as default disbursement account
                </Button>
              ) : null}

              {statusTransitions[account.status].map((target) => (
                <ReasonActionButton
                  key={target}
                  label={`Mark as ${statusLabels[target]}`}
                  variant={target === 'closed' || target === 'suspended' ? 'destructive' : 'secondary'}
                  reasonLabel="Reason"
                  onConfirm={(reason) => changeStatus.mutateAsync({ status: target, reason })}
                />
              ))}
            </div>
            <p className="mt-3 text-xs text-slate-500">
              There is no delete: every repayment and disbursement on record names one of these accounts permanently.
            </p>
          </Card>

          <Card className="max-w-2xl">
            <h2 className="mb-4 text-sm font-semibold text-slate-900">Details</h2>
            <div className="grid grid-cols-2 gap-4">
              <Detail label="Bank code" value={account.bank_code} />
              <Detail label="Branch" value={account.branch_name} />
              <Detail label="Currency" value={account.currency} />
              <Detail label="Purposes" value={account.purpose_labels.join(', ')} />
              <Detail label="Approved by" value={account.approved_by} />
              <Detail label="Approved at" value={formatDateTime(account.approved_at)} />
            </div>
          </Card>

          <p className="text-xs text-slate-400">Last updated {formatDateTime(account.updated_at)}</p>
        </div>
      ) : null}
    </QueryState>
  );
}
