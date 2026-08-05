<?php

declare(strict_types=1);

use App\Domains\Audit\Http\Controllers\AuditLogController;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Reports\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Audit log
|--------------------------------------------------------------------------
|
| Read-only. AuditLogger already writes an entry for every sensitive action
| across every domain; this is the one place they are all visible together.
|
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])
    ->prefix('audit-logs')
    ->name('audit.')
    ->group(function (): void {
        Route::get('/', [AuditLogController::class, 'index'])
            ->middleware('permission:'.Permission::AuditView->value)
            ->name('index');

        Route::get('{auditLog}', [AuditLogController::class, 'show'])
            ->middleware('permission:'.Permission::AuditView->value)
            ->whereNumber('auditLog')
            ->name('show');
    });

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])
    ->get('reports/compliance-overview', [ReportController::class, 'complianceOverview'])
    ->middleware('permission:'.Permission::AuditView->value)
    ->name('reports.compliance-overview');
