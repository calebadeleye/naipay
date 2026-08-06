'use client';

import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { useCurrentStaff } from '@/lib/auth/use-auth';
import { formatDateTime } from '@/lib/format';

/**
 * Turns a dotted permission name into the domain it belongs to — the part
 * before the first dot — so a long flat list reads as sections instead of
 * one wall of badges. "merchants.create" groups under "Merchants".
 */
function permissionGroup(permission: string): string {
  const [domain] = permission.split('.');

  return domain
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

function groupPermissions(permissions: string[]): Array<[string, string[]]> {
  const groups = new Map<string, string[]>();

  for (const permission of permissions) {
    const group = permissionGroup(permission);
    const existing = groups.get(group) ?? [];
    existing.push(permission);
    groups.set(group, existing);
  }

  return Array.from(groups.entries()).sort(([a], [b]) => a.localeCompare(b));
}

export default function AccountPage() {
  const { data: staff, isLoading, error } = useCurrentStaff();

  return (
    <>
      <PageHeader title="My profile" description="Your account, roles and granted permissions." />

      <QueryState isLoading={isLoading} error={error}>
        {staff ? (
          <div className="space-y-6">
            <Card>
              <div className="flex items-start justify-between gap-4">
                <div>
                  <h2 className="text-lg font-semibold text-slate-900">{staff.full_name}</h2>
                  <p className="text-sm text-slate-500">{staff.job_title ?? staff.roles[0]}</p>
                </div>
                <Badge tone={staff.status === 'active' ? 'success' : 'neutral'}>
                  {staff.status_label}
                </Badge>
              </div>

              <dl className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <dt className="text-xs font-medium tracking-wide text-slate-400 uppercase">
                    Staff number
                  </dt>
                  <dd className="numeric mt-1 text-sm text-slate-900">{staff.staff_number}</dd>
                </div>
                <div>
                  <dt className="text-xs font-medium tracking-wide text-slate-400 uppercase">Email</dt>
                  <dd className="mt-1 text-sm text-slate-900">{staff.email}</dd>
                </div>
                <div>
                  <dt className="text-xs font-medium tracking-wide text-slate-400 uppercase">Phone</dt>
                  <dd className="mt-1 text-sm text-slate-900">{staff.phone ?? '—'}</dd>
                </div>
                <div>
                  <dt className="text-xs font-medium tracking-wide text-slate-400 uppercase">
                    Two-factor authentication
                  </dt>
                  <dd className="mt-1 text-sm text-slate-900">
                    {staff.two_factor.enabled ? 'Enabled' : staff.two_factor.required ? 'Required, not yet set up' : 'Not enabled'}
                  </dd>
                </div>
                <div>
                  <dt className="text-xs font-medium tracking-wide text-slate-400 uppercase">
                    Last signed in
                  </dt>
                  <dd className="mt-1 text-sm text-slate-900">{formatDateTime(staff.last_login_at)}</dd>
                </div>
                <div>
                  <dt className="text-xs font-medium tracking-wide text-slate-400 uppercase">
                    Last sign-in from
                  </dt>
                  <dd className="numeric mt-1 text-sm text-slate-900">{staff.last_login_ip ?? '—'}</dd>
                </div>
              </dl>
            </Card>

            <Card>
              <h2 className="text-sm font-semibold text-slate-900">Roles</h2>
              <p className="mt-1 text-xs text-slate-500">
                What you can do is driven entirely by these roles, not your job title.
              </p>
              <div className="mt-4 flex flex-wrap gap-2">
                {staff.roles.length > 0 ? (
                  staff.roles.map((role) => (
                    <Badge key={role} tone="info">
                      {role}
                    </Badge>
                  ))
                ) : (
                  <p className="text-sm text-slate-500">No roles assigned yet.</p>
                )}
              </div>
            </Card>

            <Card>
              <h2 className="text-sm font-semibold text-slate-900">Permissions</h2>
              <p className="mt-1 text-xs text-slate-500">
                Every permission your roles grant — {staff.permissions.length} in total. The API
                re-checks each of these on every request, whatever the console renders.
              </p>

              <div className="mt-4 space-y-5">
                {groupPermissions(staff.permissions).map(([group, permissions]) => (
                  <div key={group}>
                    <h3 className="text-xs font-semibold tracking-wide text-slate-400 uppercase">
                      {group}
                    </h3>
                    <div className="mt-2 flex flex-wrap gap-2">
                      {permissions.sort().map((permission) => (
                        <Badge key={permission} tone="neutral">
                          {permission}
                        </Badge>
                      ))}
                    </div>
                  </div>
                ))}

                {staff.permissions.length === 0 ? (
                  <p className="text-sm text-slate-500">
                    No permissions granted yet — ask an administrator to assign you a role.
                  </p>
                ) : null}
              </div>
            </Card>
          </div>
        ) : null}
      </QueryState>
    </>
  );
}
