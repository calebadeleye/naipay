/** Mirrors App\Domains\Notifications\Http\Resources\StaffNotificationResource. */
export interface StaffNotification {
  id: number;
  type: string;
  title: string;
  body: string | null;
  action_url: string | null;
  read: boolean;
  read_at: string | null;
  created_at: string;
}
