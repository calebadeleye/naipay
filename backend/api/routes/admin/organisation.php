<?php

declare(strict_types=1);

use App\Domains\Branches\Http\Controllers\BranchController;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Staff\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Branches, staff and organisational structure
|--------------------------------------------------------------------------
|
| Mounted under /api/v1/admin. Every route sits behind the full stack:
| authentication, idle-session expiry, and completion of any outstanding
| security steps.
|
| Permissions are declared on the route as well as in each Form Request. The
| duplication is deliberate — a request class that forgets its authorize()
| would otherwise leave an endpoint open, and the route middleware is the
| layer that is visible when reading the API surface.
|
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])->group(function (): void {

    // --- Branches ---------------------------------------------------------
    Route::prefix('branches')->name('branches.')->group(function (): void {
        Route::get('/', [BranchController::class, 'index'])
            ->middleware('permission:'.Permission::BranchesView->value)
            ->name('index');

        // Used by every branch picker in the console.
        Route::get('options', [BranchController::class, 'options'])
            ->middleware('permission:'.Permission::BranchesView->value)
            ->name('options');

        Route::post('/', [BranchController::class, 'store'])
            ->middleware('permission:'.Permission::BranchesManage->value)
            ->name('store');

        Route::get('{branch}', [BranchController::class, 'show'])
            ->middleware('permission:'.Permission::BranchesView->value)
            ->whereNumber('branch')
            ->name('show');

        Route::patch('{branch}', [BranchController::class, 'update'])
            ->middleware('permission:'.Permission::BranchesManage->value)
            ->whereNumber('branch')
            ->name('update');

        // Suspend, close or reactivate. There is no delete: a branch is
        // referenced by every loan and repayment booked against it.
        Route::post('{branch}/status', [BranchController::class, 'changeStatus'])
            ->middleware('permission:'.Permission::BranchesManage->value)
            ->whereNumber('branch')
            ->name('status');
    });

    // --- Staff ------------------------------------------------------------
    Route::prefix('staff')->name('staff.')->group(function (): void {
        Route::get('/', [StaffController::class, 'index'])
            ->middleware('permission:'.Permission::StaffView->value)
            ->name('index');

        Route::post('/', [StaffController::class, 'store'])
            ->middleware('permission:'.Permission::StaffCreate->value)
            ->name('store');

        Route::get('{staff}', [StaffController::class, 'show'])
            ->middleware('permission:'.Permission::StaffView->value)
            ->whereNumber('staff')
            ->name('show');

        Route::patch('{staff}', [StaffController::class, 'update'])
            ->middleware('permission:'.Permission::StaffUpdate->value)
            ->whereNumber('staff')
            ->name('update');

        Route::post('{staff}/transfer', [StaffController::class, 'transfer'])
            ->middleware('permission:'.Permission::StaffUpdate->value)
            ->whereNumber('staff')
            ->name('transfer');

        // Status changes revoke access and cut every live session.
        Route::post('{staff}/suspend', [StaffController::class, 'suspend'])
            ->middleware('permission:'.Permission::StaffDisable->value)
            ->whereNumber('staff')
            ->name('suspend');

        Route::post('{staff}/reinstate', [StaffController::class, 'reinstate'])
            ->middleware('permission:'.Permission::StaffDisable->value)
            ->whereNumber('staff')
            ->name('reinstate');

        Route::post('{staff}/disable', [StaffController::class, 'disable'])
            ->middleware('permission:'.Permission::StaffDisable->value)
            ->whereNumber('staff')
            ->name('disable');

        // Privilege changes carry their own permissions, held by the Super
        // Administrator alone.
        Route::put('{staff}/roles', [StaffController::class, 'assignRoles'])
            ->middleware('permission:'.Permission::StaffAssignRoles->value)
            ->whereNumber('staff')
            ->name('roles');

        Route::put('{staff}/approval-limit', [StaffController::class, 'setApprovalLimit'])
            ->middleware('permission:'.Permission::StaffSetApprovalLimit->value)
            ->whereNumber('staff')
            ->name('approval-limit');

        Route::post('{staff}/reset-password', [StaffController::class, 'resetPassword'])
            ->middleware('permission:'.Permission::StaffUpdate->value)
            ->whereNumber('staff')
            ->name('reset-password');
    });
});
