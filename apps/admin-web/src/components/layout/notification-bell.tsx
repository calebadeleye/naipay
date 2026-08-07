'use client';

import { useRouter } from 'next/navigation';
import { Bell } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { cn } from '@/lib/cn';
import { formatRelative } from '@/lib/format';
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotifications,
} from '@/lib/notifications/use-notifications';
import type { StaffNotification } from '@/lib/notifications/types';

/**
 * The bell in the topbar: a staff member's own approvals, rejections and
 * other maker-checker decisions, surfaced without having to reopen the
 * record they acted on. Polls every 30s (`useNotifications`) rather than
 * pushing — see that hook for why.
 */
export function NotificationBell() {
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const router = useRouter();

  const { data } = useNotifications();
  const markRead = useMarkNotificationRead();
  const markAllRead = useMarkAllNotificationsRead();

  const notifications = data?.items ?? [];
  const unreadCount = data?.unreadCount ?? 0;

  useEffect(() => {
    if (!open) return;

    function handlePointerDown(event: PointerEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setOpen(false);
      }
    }

    document.addEventListener('pointerdown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);

    return () => {
      document.removeEventListener('pointerdown', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [open]);

  function handleSelect(notification: StaffNotification) {
    if (!notification.read) {
      markRead.mutate(notification.id);
    }

    setOpen(false);

    if (notification.action_url) {
      router.push(notification.action_url);
    }
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        aria-label={unreadCount > 0 ? `Notifications, ${unreadCount} unread` : 'Notifications'}
        onClick={() => setOpen((value) => !value)}
        className="relative rounded-md p-1.5 text-slate-600 hover:bg-slate-100 hover:text-slate-900"
      >
        <Bell className="size-5" aria-hidden />
        {unreadCount > 0 ? (
          <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold text-white">
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        ) : null}
      </button>

      {open ? (
        <div className="glass-surface absolute top-full right-0 z-20 mt-2 w-80 max-w-[90vw] rounded-xl p-0 shadow-lg">
          <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <p className="text-sm font-semibold text-slate-900">Notifications</p>
            {unreadCount > 0 ? (
              <button
                type="button"
                onClick={() => markAllRead.mutate()}
                disabled={markAllRead.isPending}
                className="text-xs font-medium text-brand-700 hover:underline disabled:opacity-60"
              >
                Mark all as read
              </button>
            ) : null}
          </div>

          <div className="max-h-96 overflow-y-auto">
            {notifications.length === 0 ? (
              <p className="px-4 py-6 text-center text-sm text-slate-500">Nothing here yet.</p>
            ) : (
              <ul>
                {notifications.map((notification) => (
                  <li key={notification.id}>
                    <button
                      type="button"
                      onClick={() => handleSelect(notification)}
                      className={cn(
                        'flex w-full items-start gap-2.5 border-b border-slate-100 px-4 py-3 text-left last:border-0 hover:bg-slate-50',
                        !notification.read && 'bg-brand-50/60',
                      )}
                    >
                      <span
                        className={cn(
                          'mt-1.5 size-1.5 shrink-0 rounded-full',
                          notification.read ? 'bg-transparent' : 'bg-brand-600',
                        )}
                        aria-hidden
                      />
                      <span className="min-w-0 flex-1">
                        <span
                          className={cn(
                            'block text-sm text-slate-900',
                            !notification.read && 'font-semibold',
                          )}
                        >
                          {notification.title}
                        </span>
                        {notification.body ? (
                          <span className="mt-0.5 block truncate text-xs text-slate-500">{notification.body}</span>
                        ) : null}
                        <span className="mt-0.5 block text-[11px] text-slate-400">
                          {formatRelative(notification.created_at)}
                        </span>
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      ) : null}
    </div>
  );
}
