<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\Permission;
use App\Domains\Repayments\Http\Controllers\RepaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Repayments
|--------------------------------------------------------------------------
|
| Recording and verifying a repayment never move money — an officer reports
| what the bank shows, and a second officer confirms it against the
| evidence. Approval is the one step that allocates the amount against the
| loan's schedule and posts the ledger entry, gated by `repayment.approve`
| maker-checker; reversal undoes it exactly, gated by `repayment.reverse`.
|
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])
    ->prefix('repayments')
    ->name('repayments.')
    ->group(function (): void {
        Route::get('/', [RepaymentController::class, 'index'])
            ->middleware('permission:'.Permission::RepaymentsView->value)
            ->name('index');

        Route::post('/', [RepaymentController::class, 'store'])
            ->middleware('permission:'.Permission::RepaymentsRecord->value)
            ->name('store');

        Route::get('{repayment}', [RepaymentController::class, 'show'])
            ->middleware('permission:'.Permission::RepaymentsView->value)
            ->whereNumber('repayment')
            ->name('show');

        Route::post('{repayment}/verify', [RepaymentController::class, 'verify'])
            ->middleware('permission:'.Permission::RepaymentsVerify->value)
            ->whereNumber('repayment')
            ->name('verify');

        Route::post('{repayment}/reject', [RepaymentController::class, 'reject'])
            ->middleware('permission:'.Permission::RepaymentsVerify->value)
            ->whereNumber('repayment')
            ->name('reject');

        Route::post('{repayment}/approve', [RepaymentController::class, 'approve'])
            ->middleware('permission:'.Permission::RepaymentsApprove->value)
            ->whereNumber('repayment')
            ->name('approve');

        Route::post('{repayment}/reverse', [RepaymentController::class, 'reverse'])
            ->middleware('permission:'.Permission::RepaymentsReverse->value)
            ->whereNumber('repayment')
            ->name('reverse');
    });
