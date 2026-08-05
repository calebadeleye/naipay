/** Mirrors App\Domains\Audit\Http\Resources\AuditLogResource. */

export interface AuditLog {
  id: number;

  action: string;
  module: string;
  event_type: string;

  auditable_type: string | null;
  auditable_id: number | null;
  auditable_reference: string | null;

  /** Only present on the detail (show) response. Paired old and new per changed field. */
  changes?: Record<string, { old: unknown; new: unknown }> | null;

  reason: string | null;

  actor: {
    staff_id: number | null;
    name: string | null;
    /** A plain string column, not an array — e.g. "super-administrator". */
    roles: string | null;
  };

  ip_address: string | null;
  correlation_id: string | null;

  created_at: string | null;
}
