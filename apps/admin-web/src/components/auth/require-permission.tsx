'use client';

import { useRouter } from 'next/navigation';
import { useEffect, type ReactNode } from 'react';

import { useHasPermission } from '@/lib/auth/use-permission';

/**
 * Guards a screen a staff member can reach only by permission — a create
 * form, most often. Reached directly by URL despite the entry point being
 * hidden (a bookmark, a shared link, an old habit), it quietly sends them
 * back to the dashboard rather than rendering the form and letting the API
 * refuse the eventual submit, or naming the permission they're missing.
 * Nothing is shown while that decision is being made, matching how the rest
 * of the console never confirms a resource exists to someone not allowed to
 * see it.
 */
export function RequirePermission({
  permission,
  children,
}: {
  permission: string;
  children: ReactNode;
}) {
  const router = useRouter();
  const hasPermission = useHasPermission(permission);

  useEffect(() => {
    if (hasPermission === false) {
      router.replace('/');
    }
  }, [hasPermission, router]);

  if (!hasPermission) {
    return null;
  }

  return <>{children}</>;
}
