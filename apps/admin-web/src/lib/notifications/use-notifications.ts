'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import type { StaffNotification } from '@/lib/notifications/types';

export const notificationKeys = {
  list: () => ['notifications', 'list'] as const,
};

/**
 * Polls rather than pushing — there is no websocket/SSE infrastructure in
 * this app yet, so a 30s interval is the pragmatic way to keep the bell
 * current without one.
 */
const POLL_INTERVAL_MS = 30_000;

export function useNotifications() {
  return useQuery({
    queryKey: notificationKeys.list(),
    queryFn: async ({ signal }) => {
      const { data, meta } = await api.getWithMeta<StaffNotification[]>('/admin/notifications', {
        signal,
        query: { per_page: 20 },
      });

      return {
        items: data,
        unreadCount: typeof meta.unread_count === 'number' ? meta.unread_count : 0,
      };
    },
    refetchInterval: POLL_INTERVAL_MS,
  });
}

export function useMarkNotificationRead() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => api.post<StaffNotification>(`/admin/notifications/${id}/read`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: notificationKeys.list() });
    },
  });
}

export function useMarkAllNotificationsRead() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => api.post<null>('/admin/notifications/read-all'),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: notificationKeys.list() });
    },
  });
}
