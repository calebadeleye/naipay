<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\Permission;
use App\Domains\Reconciliation\Http\Controllers\BankReconciliationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Bank reconciliation
|--------------------------------------------------------------------------
|
| Manual, in this release: every statement line is an officer's own
| transcription, matched against a repayment or a loan disbursement.
| Submission is refused while any line remains unmatched, and approval is
| maker-checked against whoever did the matching.
|
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])
    ->prefix('reconciliations')
    ->name('reconciliation.')
    ->group(function (): void {
        Route::get('/', [BankReconciliationController::class, 'index'])
            ->middleware('permission:'.Permission::ReconciliationView->value)
            ->name('index');

        Route::post('/', [BankReconciliationController::class, 'store'])
            ->middleware('permission:'.Permission::ReconciliationMatch->value)
            ->name('store');

        Route::get('{reconciliation}', [BankReconciliationController::class, 'show'])
            ->middleware('permission:'.Permission::ReconciliationView->value)
            ->whereNumber('reconciliation')
            ->name('show');

        Route::post('{reconciliation}/submit', [BankReconciliationController::class, 'submit'])
            ->middleware('permission:'.Permission::ReconciliationMatch->value)
            ->whereNumber('reconciliation')
            ->name('submit');

        Route::post('{reconciliation}/approve', [BankReconciliationController::class, 'approve'])
            ->middleware('permission:'.Permission::ReconciliationApprove->value)
            ->whereNumber('reconciliation')
            ->name('approve');

        Route::prefix('{reconciliation}/lines')
            ->whereNumber('reconciliation')
            ->name('lines.')
            ->group(function (): void {
                Route::post('/', [BankReconciliationController::class, 'addLine'])
                    ->middleware('permission:'.Permission::ReconciliationMatch->value)
                    ->name('store');

                Route::get('{line}/suggestions', [BankReconciliationController::class, 'suggestions'])
                    ->middleware('permission:'.Permission::ReconciliationMatch->value)
                    ->whereNumber('line')
                    ->name('suggestions');

                Route::post('{line}/match', [BankReconciliationController::class, 'matchLine'])
                    ->middleware('permission:'.Permission::ReconciliationMatch->value)
                    ->whereNumber('line')
                    ->name('match');

                Route::post('{line}/unmatch', [BankReconciliationController::class, 'unmatchLine'])
                    ->middleware('permission:'.Permission::ReconciliationMatch->value)
                    ->whereNumber('line')
                    ->name('unmatch');

                Route::post('{line}/exclude', [BankReconciliationController::class, 'excludeLine'])
                    ->middleware('permission:'.Permission::ReconciliationMatch->value)
                    ->whereNumber('line')
                    ->name('exclude');
            });
    });
