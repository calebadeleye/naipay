'use client';

import { useCurrentStaff } from '@/lib/auth/use-auth';

/**
 * Whether the signed-in staff member holds a permission.
 *
 * `undefined` while the session is still loading — deliberately distinct
 * from `false`, so a caller can tell "not yet known" from "known and
 * refused" and avoid a flash of hidden content while `/admin/auth/me` is
 * still in flight.
 */
export function useHasPermission(permission: string): boolean | undefined {
  const { data: staff } = useCurrentStaff();

  if (!staff) {
    return undefined;
  }

  return staff.permissions.includes(permission);
}
