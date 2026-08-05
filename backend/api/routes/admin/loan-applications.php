<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\Permission;
use App\Domains\LoanApplications\Http\Controllers\LoanApplicationController;
use App\Domains\LoanApplications\Http\Controllers\LoanApplicationGuarantorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loan applications
|--------------------------------------------------------------------------
|
| The workflow is expressed as distinct action endpoints rather than a status
| field on a general update, the same reasoning as the merchant onboarding
| routes: each step carries its own permission, several require a recorded
| reason, and approval is maker-checked.
|
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])
    ->prefix('loan-applications')
    ->name('loan-applications.')
    ->group(function (): void {
        Route::get('/', [LoanApplicationController::class, 'index'])
            ->middleware('permission:'.Permission::LoanApplicationsView->value)
            ->name('index');

        Route::post('/', [LoanApplicationController::class, 'store'])
            ->middleware('permission:'.Permission::LoanApplicationsCreate->value)
            ->name('store');

        Route::get('{application}', [LoanApplicationController::class, 'show'])
            ->middleware('permission:'.Permission::LoanApplicationsView->value)
            ->whereNumber('application')
            ->name('show');

        Route::patch('{application}', [LoanApplicationController::class, 'update'])
            ->middleware('permission:'.Permission::LoanApplicationsUpdate->value)
            ->whereNumber('application')
            ->name('update');

        // --- Workflow ----------------------------------------------------
        Route::post('{application}/submit', [LoanApplicationController::class, 'submit'])
            ->middleware('permission:'.Permission::LoanApplicationsUpdate->value)
            ->whereNumber('application')
            ->name('submit');

        Route::post('{application}/assess', [LoanApplicationController::class, 'assess'])
            ->middleware('permission:'.Permission::LoanApplicationsAssess->value)
            ->whereNumber('application')
            ->name('assess');

        Route::post('{application}/recommend', [LoanApplicationController::class, 'recommend'])
            ->middleware('permission:'.Permission::LoanApplicationsRecommend->value)
            ->whereNumber('application')
            ->name('recommend');

        Route::post('{application}/approve', [LoanApplicationController::class, 'approve'])
            ->middleware('permission:'.Permission::LoanApplicationsApprove->value)
            ->whereNumber('application')
            ->name('approve');

        Route::post('{application}/reject', [LoanApplicationController::class, 'reject'])
            ->middleware('permission:'.Permission::LoanApplicationsReject->value)
            ->whereNumber('application')
            ->name('reject');

        Route::post('{application}/return-to-draft', [LoanApplicationController::class, 'returnToDraft'])
            ->middleware('permission:'.Permission::LoanApplicationsUpdate->value)
            ->whereNumber('application')
            ->name('return-to-draft');

        Route::post('{application}/withdraw', [LoanApplicationController::class, 'withdraw'])
            ->middleware('permission:'.Permission::LoanApplicationsUpdate->value)
            ->whereNumber('application')
            ->name('withdraw');

        // --- Guarantors ----------------------------------------------------
        Route::prefix('{application}/guarantors')
            ->whereNumber('application')
            ->name('guarantors.')
            ->group(function (): void {
                Route::get('/', [LoanApplicationGuarantorController::class, 'index'])
                    ->middleware('permission:'.Permission::LoanApplicationsView->value)
                    ->name('index');

                Route::post('/', [LoanApplicationGuarantorController::class, 'store'])
                    ->middleware('permission:'.Permission::LoanApplicationsUpdate->value)
                    ->name('store');

                Route::delete('{guarantor}', [LoanApplicationGuarantorController::class, 'destroy'])
                    ->middleware('permission:'.Permission::LoanApplicationsUpdate->value)
                    ->whereNumber('guarantor')
                    ->name('destroy');
            });
    });
