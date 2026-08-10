<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\Permission;
use App\Domains\Investors\Http\Controllers\InvestorManagementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Investor account administration
|--------------------------------------------------------------------------
|
| Mounted under /api/v1/admin. Managing an investor's account (creating one,
| editing its profile, suspending or reinstating it) is a staff action behind
| the `auth:staff` guard — distinct from routes/investor/*.php, which is the
| investor's own read-only dashboard behind the separate `investor` guard.
|
| Permissions are declared on the route as well as in each Form Request; see
| routes/admin/organisation.php for why the duplication is deliberate.
|
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])->group(function (): void {

    Route::prefix('investors')->name('investors.')->group(function (): void {
        Route::get('/', [InvestorManagementController::class, 'index'])
            ->middleware('permission:'.Permission::InvestorsView->value)
            ->name('index');

        Route::post('/', [InvestorManagementController::class, 'store'])
            ->middleware('permission:'.Permission::InvestorsCreate->value)
            ->name('store');

        Route::get('{investor}', [InvestorManagementController::class, 'show'])
            ->middleware('permission:'.Permission::InvestorsView->value)
            ->whereNumber('investor')
            ->name('show');

        Route::patch('{investor}', [InvestorManagementController::class, 'update'])
            ->middleware('permission:'.Permission::InvestorsUpdate->value)
            ->whereNumber('investor')
            ->name('update');

        // Status changes revoke portal access and cut every live session.
        Route::post('{investor}/suspend', [InvestorManagementController::class, 'suspend'])
            ->middleware('permission:'.Permission::InvestorsSuspend->value)
            ->whereNumber('investor')
            ->name('suspend');

        Route::post('{investor}/reinstate', [InvestorManagementController::class, 'reinstate'])
            ->middleware('permission:'.Permission::InvestorsSuspend->value)
            ->whereNumber('investor')
            ->name('reinstate');
    });
});
