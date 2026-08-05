<?php

declare(strict_types=1);

namespace App\Domains\Audit\Http\Controllers;

use App\Domains\Audit\Http\Resources\AuditLogResource;
use App\Domains\Audit\Models\AuditLog;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only, by construction: AuditLog itself refuses an update or a delete
 * (see its own booted() guards), so there is nothing here beyond viewing.
 */
final class AuditLogController
{
    public function index(Request $request): JsonResponse
    {
        $specification = QuerySpecification::make(
            searchable: ['action', 'auditable_reference', 'actor_name'],
            filters: [
                'module' => FilterType::In,
                'action' => FilterType::In,
                'event_type' => FilterType::In,
                'staff_id' => FilterType::Exact,
                'auditable_type' => FilterType::Exact,
                'auditable_id' => FilterType::Exact,
                'created_at' => FilterType::DateRange,
            ],
            sortable: ['created_at', 'module', 'action'],
            defaultSort: ['-created_at'],
        );

        $logs = QueryPipeline::for($request, $specification)->paginate(AuditLog::query());

        return ApiResponse::paginated(
            $logs->through(fn (AuditLog $log) => new AuditLogResource($log)),
            message: 'Audit log retrieved.',
        );
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        return ApiResponse::success(new AuditLogResource($auditLog), 'Audit log entry retrieved.');
    }
}
