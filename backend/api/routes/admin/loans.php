<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\Permission;
use App\Domains\Loans\Http\Controllers\LoanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loans
|--------------------------------------------------------------------------
|
| A loan is created automatically when its originating application is
| approved — see LoanApplicationService::approve() — so there is no create
| route here. What remains is the path from that automatic creation through
| to money actually moving: a second confirmation (`loan.approve`), the
| finance-only release of funds (`loan.disburse`), and, if it comes to it, a
| write-off. Each step is maker-checked against the one before it.
|
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])
    ->prefix('loans')
    ->name('loans.')
    ->group(function (): void {
        Route::get('/', [LoanController::class, 'index'])
            ->middleware('permission:'.Permission::LoansView->value)
            ->name('index');

        Route::get('{loan}', [LoanController::class, 'show'])
            ->middleware('permission:'.Permission::LoansView->value)
            ->whereNumber('loan')
            ->name('show');

        Route::get('{loan}/schedule/pdf', [LoanController::class, 'schedulePdf'])
            ->middleware('permission:'.Permission::LoansView->value)
            ->whereNumber('loan')
            ->name('schedule.pdf');

        Route::post('{loan}/schedule/email', [LoanController::class, 'emailSchedule'])
            ->middleware('permission:'.Permission::LoansView->value)
            ->whereNumber('loan')
            ->name('schedule.email');

        Route::post('{loan}/approve', [LoanController::class, 'approve'])
            ->middleware('permission:'.Permission::LoansApprove->value)
            ->whereNumber('loan')
            ->name('approve');

        Route::post('{loan}/disburse', [LoanController::class, 'disburse'])
            ->middleware('permission:'.Permission::LoansDisburse->value)
            ->whereNumber('loan')
            ->name('disburse');

        Route::post('{loan}/write-off', [LoanController::class, 'writeOff'])
            ->middleware('permission:'.Permission::LoansWriteOff->value)
            ->whereNumber('loan')
            ->name('write-off');

        // Calendar-only shift of the repayment schedule (public holiday, or a
        // merchant's request). Moves no money and changes no figures.
        Route::post('{loan}/reschedule', [LoanController::class, 'reschedule'])
            ->middleware('permission:'.Permission::LoansRestructure->value)
            ->whereNumber('loan')
            ->name('reschedule');
    });
