<?php

declare(strict_types=1);

namespace App\Domains\Audit\Http\Resources;

use App\Domains\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
final class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AuditLog $log */
        $log = $this->resource;

        return [
            'id' => $log->id,

            'action' => $log->action,
            'module' => $log->module,
            'event_type' => $log->event_type,

            'auditable_type' => $log->auditable_type,
            'auditable_id' => $log->auditable_id,
            'auditable_reference' => $log->auditable_reference,

            'changes' => $this->when($request->routeIs('*.show'), fn () => $log->changes()),

            'reason' => $log->reason,

            'actor' => [
                'staff_id' => $log->staff_id,
                'name' => $log->actor_name,
                'roles' => $log->actor_roles,
            ],

            'ip_address' => $log->ip_address,
            'correlation_id' => $log->correlation_id,

            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
